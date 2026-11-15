<?php

declare(strict_types=1);

namespace IndianOpinion\FeedItemBrief\Model;

use IndianOpinion\FeedItemBrief\Exception\ProviderException;
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\AuthenticationException;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedAttemptException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\HttpException;
use IndianOpinion\LlmJson\Exception\LlmJsonException;
use IndianOpinion\LlmJson\Exception\TransportException;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\WordPressTransport;

/**
 * Model calls through indianopinion/llm-json-php. One attempt per call:
 * the plugin's own queue does the retrying, so the cap counts every call.
 */
final class LlmJsonClient implements ModelClient
{
    public const STORE_PREFIX = 'feed_item_brief_llm';

    public function __construct(
        private readonly string $provider,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = '',
    ) {
    }

    public static function available(): bool
    {
        return class_exists(Client::class);
    }

    public function complete(array $messages, array $schema, int $timeoutSeconds): ModelResponse
    {
        if ($this->model === '' || ($this->apiKey === '' && $this->provider !== 'generic')) {
            throw new ProviderException('No model or API key is configured.', ProviderException::CONFIG, false);
        }
        try {
            $provider = match ($this->provider) {
                'openai' => Provider::openAi($this->apiKey),
                'generic' => Provider::generic('generic', $this->baseUrl, $this->apiKey, $this->apiKey === ''),
                default => Provider::openRouter($this->apiKey, function_exists('home_url') ? home_url('/') : null, 'feed-item-brief'),
            };
            $timeout = (float) max(5, $timeoutSeconds);
            $client = Client::create(Chain::of(Route::to($provider, $this->model)), new WordPressTransport())
                ->withStore(new WordPressTransientStore())
                ->withOptions(
                    Options::defaults()
                        ->withAttemptsPerRoute(1)
                        ->withTimeouts(min(10.0, $timeout), $timeout)
                        ->withDeadline($timeout + 5.0)
                        ->withStoreKeyPrefix(self::STORE_PREFIX)
                );
            $request = Request::for('feed-item-brief.summarise', Schema::fromArray('feed_item_brief', $schema))
                ->withMessages($messages)
                ->withTimeouts(null, $timeout);
            $result = $client->complete($request);
        } catch (CreditExhaustedException $e) {
            throw new ProviderException('Provider reports no credit.', ProviderException::CREDIT, true, $e);
        } catch (ChainExhaustedException $e) {
            throw self::map($e->lastError() ?? $e, $e);
        } catch (ConfigurationException $e) {
            throw new ProviderException('Model configuration error: ' . $e->getMessage(), ProviderException::CONFIG, false, $e);
        } catch (LlmJsonException $e) {
            throw self::map($e, $e);
        }
        $usage = $result->totalUsage();

        return new ModelResponse($result->data(), $result->model(), $usage->promptTokens, $usage->completionTokens);
    }

    private static function map(\Throwable $error, \Throwable $previous): ProviderException
    {
        if ($error instanceof AuthenticationException) {
            return new ProviderException('Provider rejected the API key.', ProviderException::AUTH, true, $previous);
        }
        if ($error instanceof CreditExhaustedAttemptException || $error instanceof CreditExhaustedException) {
            return new ProviderException('Provider reports no credit.', ProviderException::CREDIT, true, $previous);
        }
        if ($error instanceof HttpException) {
            $status = $error->status();
            if ($status === 401 || $status === 403) {
                return new ProviderException('Provider rejected the API key (HTTP ' . $status . ').', ProviderException::AUTH, true, $previous);
            }
            if ($status === 402) {
                return new ProviderException('Provider reports no credit (HTTP 402).', ProviderException::CREDIT, true, $previous);
            }

            return new ProviderException('Provider error (HTTP ' . $status . ').', ProviderException::TRANSIENT, true, $previous);
        }
        if ($error instanceof ConfigurationException) {
            return new ProviderException('Model configuration error: ' . $error->getMessage(), ProviderException::CONFIG, false, $previous);
        }
        $reached = !$error instanceof TransportException;

        return new ProviderException('Model call failed: ' . self::redact($error->getMessage()), ProviderException::TRANSIENT, $reached, $previous);
    }

    private static function redact(string $message): string
    {
        return (string) preg_replace('/(sk-|Bearer\s+)[A-Za-z0-9_\-]{8,}/', '$1[redacted]', $message);
    }
}
