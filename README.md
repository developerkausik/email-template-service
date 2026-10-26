# Email Template Service

A Laravel microservice that writes short, customer-friendly email templates. You send a purpose, a recipient name and a tone; an AI model (Anthropic's Claude) returns a subject line and an email body ready for a staff member to review and send. Each AI call is logged with its response time.

## Tech stack

- PHP 8.2+, Laravel 12
- [Anthropic PHP SDK](https://github.com/anthropics/anthropic-sdk-php) (`anthropic-ai/sdk`)
- PHPUnit for tests
- No database

## Setup

```bash
git clone <repo-url> email-template-service
cd email-template-service
composer install
cp .env.example .env
php artisan key:generate
```

Then set the AI options in `.env`:

| Variable | Default | Purpose |
|---|---|---|
| `AI_DRIVER` | `claude` | `claude` calls the Anthropic API. `fake` returns a canned email, so you can run the service without a key. |
| `ANTHROPIC_API_KEY` | (empty) | Your key from [console.anthropic.com](https://console.anthropic.com). Required when `AI_DRIVER=claude`. |
| `AI_MODEL` | `claude-opus-5-5` | Any Claude model ID. |
| `AI_EFFORT` | `low` | How much the model reasons before answering (`low`, `medium`, `high`). |
| `AI_MAX_TOKENS` | `2048` | Output token limit per request. |
| `AI_TIMEOUT` | `30` | Seconds before an AI request is abandoned. |
| `AI_MAX_RETRIES` | `2` | The SDK retries rate limits, timeouts and 5xx errors this many times. |

The API key is read only in [`config/ai.php`](config/ai.php); application code reads `config('ai.*')` and never calls `env()`. `.env` is gitignored, and `.env.example` holds the variable names without values. If the key is missing, the API returns a clear 503 rather than failing on the first AI call.

Run it:

```bash
php artisan serve
```

The API is now at `http://127.0.0.1:8000`.

## API

### `POST /api/email-templates`

**Request**

| Field | Rules |
|---|---|
| `purpose` | Required. 5 to 500 characters. What the email should achieve. |
| `recipient_name` | Required. Up to 100 characters, no line breaks or `<` `>`. |
| `tone` | Required. One of `formal`, `friendly`, `apologetic`, `persuasive`, `neutral`. |

**Example**

```bash
curl -X POST http://127.0.0.1:8000/api/email-templates \
  -H "Content-Type: application/json" \
  -d '{"purpose": "Inform about a 2-day shipping delay", "recipient_name": "Priya", "tone": "apologetic"}'
```

**Sample response `200 OK`**

```json
{
  "subject": "Your order is running two days late",
  "body": "Hi Priya,\n\nI'm sorry to let you know that your order {{order_id}} has been delayed and will now arrive two days later than planned, on {{delivery_date}}. This is on us, and we understand it's frustrating when something you're waiting for doesn't arrive on time.\n\nYour order is packed and on its way, and you can follow its progress here: {{tracking_link}}. If the new date doesn't work for you, just reply to this email and we'll help you find the best option.\n\nThank you for your patience.\n\nBest regards,\n[Your Name]",
  "tone": "apologetic",
  "meta": {
    "model": "claude-opus-5-5",
    "ai_latency_ms": 2140,
    "request_id": "1db0dc60-715f-4b1a-9125-b374a52959b9"
  }
}
```

Response headers:
- `X-Request-Id`: the request ID. A client can send its own (8–64 letters, digits or dashes) and it will be reused.
- `X-Response-Time`: total time spent handling the request, for example `2163ms`.

### Errors

Every error is JSON with a `message`. AI failures also carry an `error` code.

| Status | When | Example body |
|---|---|---|
| 422 | A field is missing or invalid | `{"message": "The tone must be one of: formal, friendly, apologetic, persuasive, neutral.", "errors": {"tone": ["..."]}}` |
| 429 | More than 30 requests per minute from one client | `{"message": "Too Many Attempts."}` |
| 502 | The AI provider returned an error, or an unusable response | `{"message": "AI service returned an error.", "error": "ai_error"}` |
| 503 | The AI provider is rate-limiting us (sends `Retry-After`), or no API key is configured | `{"message": "AI service is not configured. Set ANTHROPIC_API_KEY or use AI_DRIVER=fake.", "error": "ai_not_configured"}` |
| 504 | The AI provider did not answer within `AI_TIMEOUT` | `{"message": "AI service timed out.", "error": "ai_timeout"}` |

A Postman collection with one request per tone and the error cases is in [`postman/email-template-service.postman_collection.json`](postman/email-template-service.postman_collection.json).

## Response-time logs

Each AI call writes one line to `storage/logs/ai.log`. The service takes a timestamp with `hrtime(true)` before and after the call and logs the difference in milliseconds ([`app/Services/EmailTemplateService.php`](app/Services/EmailTemplateService.php)).

```
[2026-10-26 06:31:41] local.INFO: ai.call {"status":"ok","model":"claude-opus-5-5","tone":"apologetic","latency_ms":2140,"input_tokens":412,"output_tokens":187} {"request_id":"1db0dc60-715f-4b1a-9125-b374a52959b9"}
[2026-10-26 06:33:05] local.WARNING: ai.call {"status":"error","error":"ai_timeout","tone":"formal","latency_ms":30012} {"request_id":"7f3c2a9e-4b1d-4e8a-9c55-0d2f6a1b8e74"}
```

- Failed calls are logged too, with the error code and the time spent before the failure.
- The `request_id` matches the `X-Request-Id` header and `meta.request_id`, so a slow response can be traced to its log line.
- `latency_ms` in the log equals `meta.ai_latency_ms` in the response. `X-Response-Time` is larger, because it also covers validation and the rest of the request.

## How the AI prompt was designed

The prompt is in [`app/Prompts/EmailPrompt.php`](app/Prompts/EmailPrompt.php), and the tone definitions are in [`app/Enums/Tone.php`](app/Enums/Tone.php).

**1. A clear role and audience.** The system prompt says the model writes emails for a customer team and that a staff member will review and send them. This sets a practical, professional style and explains why the output is a template.

**2. Tones are defined, not just named.** A single word like "persuasive" means different things to different readers and can drift between requests. Each tone has a one-line style guide that goes into the prompt:

| Tone | Instruction given to the model |
|---|---|
| formal | Polite and professional. Full sentences, no contractions, no slang, no exclamation marks. |
| friendly | Warm and conversational, like a helpful person rather than a company. Contractions are fine; stay professional. |
| apologetic | Acknowledge the problem plainly, take responsibility without making excuses, and state what happens next. |
| persuasive | Lead with the benefit to the reader, give one concrete reason to act, and end with a clear call to action. No pressure tactics. |
| neutral | Clear, plain and matter-of-fact. Inform without emotion or sales language. |

Accepting only these five values (validated with a PHP enum) keeps the output predictable and makes each tone testable.

**3. Concrete limits for "short".**
- The body is 80–150 words.
- There's a greeting with the recipient's name, one purpose, and one next step where it fits.
- The subject is under 60 characters.
- The output is plain text, with no Markdown or HTML, so it pastes straight into any email tool.

**4. No invented facts.** Left alone, a model will happily make up order numbers, dates and phone numbers to make an email sound complete. The prompt asks for placeholders such as `{{order_id}}` instead, and for a `[Your Name]` signature rather than an invented sender. The template is then safe to send once the placeholders are filled in.

**5. User input is treated as data.** The purpose, name and tone go inside XML-style tags (`<purpose>`, `<recipient_name>`, `<tone>`), and the system prompt tells the model never to follow instructions inside them. Validation also rejects line breaks and angle brackets in `recipient_name`, so a name can't close its tag and inject text.

**6. Structured output.** The request includes a JSON Schema (`output_config.format`) with exactly two fields, `subject` and `body`. The API constrains the model to that shape, so there's no free text to parse. The service still rejects an empty subject or body with a 502.

**7. Settings.**
- Effort is `low`. A 150-word email doesn't need long reasoning, and low effort keeps response times down.
- The request opts into Anthropic's server-side refusal fallback (`fallbacks: "default"`), so if the model declines, the API retries on a fallback model instead of failing.

## Project structure

```
app/
├── Contracts/AiClient.php                   Interface for any AI provider
├── Enums/Tone.php                           Allowed tones + style guide for each
├── Exceptions/AiServiceException.php        AI failures mapped to HTTP status + JSON
├── Http/Controllers/EmailTemplateController.php
├── Http/Middleware/TrackRequest.php         Request ID + X-Response-Time header
├── Http/Requests/GenerateEmailRequest.php   Input validation
├── Prompts/EmailPrompt.php                  System prompt, user prompt, JSON schema
├── Providers/AppServiceProvider.php         Chooses the AI driver from config
└── Services/
    ├── EmailTemplateService.php             Calls the AI, times and logs the call
    ├── EmailTemplate.php                    Result object
    └── Ai/
        ├── ClaudeClient.php                 Anthropic SDK implementation
        ├── FakeAiClient.php                 Offline implementation for tests and demos
        └── AiResult.php
config/ai.php                                AI settings, read from .env
config/logging.php                           "ai" log channel → storage/logs/ai.log
routes/api.php                               POST /api/email-templates
```

The controller only validates input and returns JSON. The service holds the logic and depends on the `AiClient` interface rather than on Anthropic directly, so the provider can be swapped or faked without changing the service.

## Tests

```bash
php artisan test
```

The tests use the fake driver, so they never call the real API and need no key. They cover:
- the response shape and headers;
- the prompt's content;
- the log line for successful and failed AI calls (status, tone, tokens, latency and request ID);
- validation, including tone values and injection attempts in the name;
- request-ID reuse;
- error mapping.
