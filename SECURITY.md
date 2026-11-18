# Security policy

## Reporting a vulnerability

Please email hello@indianopinion.org with a description, the affected
version and steps to reproduce. Do not open a public issue for a
security problem.

We acknowledge reports within 3 working days and aim to ship a fix or a
mitigation within 30 days, sooner for anything that exposes API keys,
allows publishing without an editor, or lets untrusted feed content run
in the browser. We credit reporters who want to be credited.

## Supported versions

The latest 0.x release receives security fixes.

## Scope notes

- Feed and page content is untrusted. It is never evaluated, never used in
  a shell, never placed in SQL without a prepared statement, and escaped
  on output.
- The API key is read from a constant or a write-only setting and is never
  printed, sent to the browser or logged.
- Prompt injection is mitigated, not solved: the source is delimited and
  labelled as data, numbers and quotes are checked against the source, and
  the attribution link never comes from the model.
