<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief;

use IndianOpinion\FeedItemBrief\Exception\CheckFailedException;
use IndianOpinion\FeedItemBrief\Exception\NoSummariserException;
use IndianOpinion\FeedItemBrief\Exception\ProviderException;
use IndianOpinion\FeedItemBrief\Model\LlmJsonClient;
use IndianOpinion\FeedItemBrief\Model\ModelClient;
use IndianOpinion\FeedItemBrief\Model\ModelResponse;

/**
 * Wraps the model call and the output checks (section 4.5). Every call,
 * the retry included, goes through the gate first, so the switch and the
 * cap are read at the moment of the call.
 */
final class Summariser
{
    public const MAX_CALLS = 2;

    /** @var \Closure(): ?ModelClient */
    private \Closure $clientFactory;

    private int $calls = 0;

    /**
     * @param \Closure(): ?ModelClient $clientFactory returns null when the library is not installed
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly Gate $gate,
        private readonly OutputChecker $checker,
        private readonly Clock $clock,
        \Closure $clientFactory,
    ) {
        $this->clientFactory = $clientFactory;
    }

    /**
     * The default factory: llm-json-php when installed, else null.
     *
     * @return \Closure(): ?ModelClient
     */
    public static function defaultFactory(Settings $settings): \Closure
    {
        return static function () use ($settings): ?ModelClient {
            if (!LlmJsonClient::available()) {
                return null;
            }

            return new LlmJsonClient($settings->string('provider'), $settings->apiKey(), $settings->model(), $settings->string('base_url'));
        };
    }

    /** Calls made by the last summarise(), including a retry. */
    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * @throws CheckFailedException
     * @throws ProviderException
     * @throws NoSummariserException
     * @throws Exception\DisabledException
     * @throws Exception\CapReachedException
     * @throws Exception\SpendDeniedException
     * @throws Exception\AuthPausedException
     */
    public function summarise(Item $item, BodyClass $class, ResolvedDate $date, bool $ignoreCap = false): Summary
    {
        $this->calls = 0;
        $source = Prompt::sourceText($class->text !== '' ? $class->text : $item->bodyText());
        $messages = Prompt::messages($item, $class, $date, $source);
        $schema = Prompt::schema();
        $failures = [];

        for ($attempt = 1; $attempt <= self::MAX_CALLS; $attempt++) {
            $reservation = $this->gate->acquire($ignoreCap);
            try {
                $response = $this->call($messages, $schema, $item);
            } catch (NoSummariserException $e) {
                $this->gate->refund($reservation);
                throw $e;
            } catch (ProviderException $e) {
                if ($e->kind() === ProviderException::CONFIG) {
                    $this->gate->refund($reservation);
                } else {
                    $this->calls++;
                    $this->gate->settle($reservation, $e->reachedProvider());
                }
                if ($e->kind() === ProviderException::AUTH) {
                    $this->gate->pause(Status::AUTH_FAILED, $e->getMessage());
                } elseif ($e->kind() === ProviderException::CREDIT) {
                    $this->gate->pause(Status::NO_CREDIT, $e->getMessage());
                }
                throw $e;
            }
            $this->calls++;
            $this->gate->settle($reservation, true, $response->promptTokens, $response->completionTokens);

            $result = $this->checker->check($response->data, $source, $item->title(), $item->language(), $class->maxWords, $date->time());
            if ($result->insufficient) {
                return new Summary($result->headline, [], $result->language, true, [], $response->model, $this->clock->now()->getTimestamp());
            }
            if ($result->passed()) {
                return new Summary(
                    $result->headline,
                    $result->paragraphs,
                    $result->language,
                    false,
                    $result->flags,
                    $response->model,
                    $this->clock->now()->getTimestamp()
                );
            }
            $failures = $result->failures;
            $messages[] = ['role' => 'assistant', 'content' => (string) json_encode($response->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            $messages[] = ['role' => 'user', 'content' => Prompt::corrective($failures)];
        }

        throw new CheckFailedException($failures);
    }

    /**
     * The filter first (case 71), then the library (case 72).
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $schema
     */
    private function call(array $messages, array $schema, Item $item): ModelResponse
    {
        $request = [
            'purpose' => 'feed-item-brief.summarise',
            'messages' => $messages,
            'schema' => $schema,
        ];
        $filtered = apply_filters('feed_item_brief_summarise', null, $request, $item);
        if ($filtered instanceof \WP_Error) {
            throw new ProviderException('Summariser error: ' . $filtered->get_error_message(), ProviderException::TRANSIENT, true);
        }
        if (is_array($filtered)) {
            return new ModelResponse($filtered, 'filter');
        }
        if ($filtered !== null) {
            throw new ProviderException('The summariser filter returned an unknown value.', ProviderException::TRANSIENT, true);
        }
        $client = ($this->clientFactory)();
        if ($client === null) {
            throw new NoSummariserException();
        }

        return $client->complete($messages, $schema, max(5, $this->settings->int('timeout')));
    }
}
