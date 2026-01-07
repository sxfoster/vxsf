# VX Unit Query API

## Overview
VX Unit Query is a lightweight PHP endpoint that proxies a Salesforce **Unit__c** SOQL query and returns the raw JSON payload. It is designed for simple, authenticated access to unit telemetry metadata with a small response cache to reduce repeated Salesforce calls.

This repository also includes an OpenAPI 3.1 description at `vx/openapi.yaml` to aid client integration.

## Features
- **Bearer-token authentication** for incoming requests.
- **Salesforce API proxying** using a server-side bearer token file.
- **5‑minute local cache** to reduce Salesforce load and provide fallback when Salesforce is unreachable.
- **OpenAPI spec** for client generation.

## Repository Layout
- `unit_query.php` — PHP endpoint that performs the Salesforce query and returns JSON.
- `vx/openapi.yaml` — OpenAPI 3.1 specification of the endpoint.
- `.secrets/` — directory intended for secrets (e.g., Salesforce bearer token).

## Requirements
- PHP 7.4+ (with cURL enabled)
- A Salesforce bearer token for API access

## Configuration
### Incoming request authentication
Requests must include a Bearer token in the `Authorization` header. The expected token is read from the `UNIT_QUERY_API_KEY` environment variable.

- **Environment variable**: `UNIT_QUERY_API_KEY`
- **Default fallback**: `REPLACE_WITH_A_LONG_RANDOM_SECRET`

> ⚠️ Set `UNIT_QUERY_API_KEY` in your runtime environment. If you do not, the endpoint will only accept the default placeholder value.

### Salesforce authentication
The endpoint uses a Salesforce bearer token stored in a local file.

- **Environment variable**: `SF_BEARER_TOKEN_FILE` (path to the token file)
- **Default file path**: `./.secrets/sf_bearer_token`

Ensure that the file is readable by the web server user.

## Running Locally
You can run the endpoint using PHP’s built‑in server for development:

```bash
php -S 0.0.0.0:8080
```

Then call the endpoint:

```bash
curl -H "Authorization: Bearer $UNIT_QUERY_API_KEY" \
  "http://localhost:8080/unit_query.php"
```

## Behavior and Response
### Successful requests
- Executes a SOQL query against Salesforce for **Unit__c** records.
- Caches the response for 300 seconds in `./.cache/unit_query.json`.
- Returns the Salesforce JSON response as-is.

### Cache behavior
If Salesforce is unreachable or returns a non‑2xx response:
- If a cached response exists, it is returned with a `cached: true` field injected.
- If no cache exists, an error response is returned.

### Errors
- **401** — Missing or malformed `Authorization` header.
- **403** — Invalid bearer token.
- **400** — Missing Salesforce bearer token file.
- **5xx** — Salesforce network or API errors.

## API Specification
The OpenAPI schema is available in `vx/openapi.yaml`. It describes:
- **Base URL**: `https://aoa.rabbitholesolution.com/vx`
- **Endpoint**: `GET /unit_query.php`
- **Auth**: Bearer token

> Note: The OpenAPI file defines optional query parameters (`unit_id`, `from`, `to`, `limit`), but the current PHP implementation does not yet apply them to the SOQL query.

## Security Notes
- Keep `.secrets/` outside of public web access.
- Store `UNIT_QUERY_API_KEY` securely in your deployment environment.
- Rotate Salesforce bearer tokens regularly.

## Contributing
1. Create a feature branch.
2. Make your changes.
3. Commit with a clear message.
4. Open a pull request describing the update.

## License
Proprietary. All rights reserved.
