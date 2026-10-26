# Email Template Service

A Laravel microservice that writes short, customer-friendly email templates. You send a purpose, a recipient name and a tone; an AI model returns a subject line and an email body ready for a staff member to review and send. Each AI call is logged with its response time.

By default it uses a free local model through [Ollama](https://ollama.com), so it runs without an API key. Change one line in `.env` to use Anthropic's Claude instead.

## Tech stack

- PHP 8.2+, Laravel 12
- [Ollama](https://ollama.com) with llama3.2 (default model, local)
- [Anthropic PHP SDK](https://github.com/anthropics/anthropic-sdk-php) (`anthropic-ai/sdk`) for Claude (optional)
- PHPUnit for tests
- No database

## Setup

```bash
git clone https://github.com/developerkausik/email-template-service.git
cd email-template-service
composer install
cp .env.example .env
php artisan key:generate
```

### Set up the model (Ollama, default)

1. Install [Ollama](https://ollama.com/download) and download the model: `ollama pull llama3.2`
2. Make sure Ollama is running (on Windows and macOS it starts with the desktop app).

`.env.example` already sets `AI_DRIVER=ollama`, so there is nothing else to configure.

- **First request.** It loads the model into memory and can take a minute or two; it may even hit `OLLAMA_TIMEOUT` and return a 504. After that, emails take 1–3 seconds on a laptop GPU.
- **Quality.** A small model like llama3.2 (3B) follows the prompt's rules less reliably than Claude. In testing it sometimes wrote under 80 words, sometimes dropped the `[Your Name]` signature, and once returned an empty body, which the service rejects with a 502. A larger model such as `qwen2.5:7b` follows the rules better if your machine can run it; pull it and set `OLLAMA_MODEL` to match.

### Settings

All AI options are in `.env`:

| Variable | Default | Purpose |
|---|---|---|
| `AI_DRIVER` | `ollama` | `ollama` runs a local model, free and offline. `claude` calls the Anthropic API. `fake` returns a canned email without calling any model. |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | Ollama server address. |
| `OLLAMA_MODEL` | `llama3.2` | Any model you have pulled in Ollama. |
| `OLLAMA_TIMEOUT` | `120` | Seconds to wait. Local models are slower, especially on the first call while the model loads. |
| `ANTHROPIC_API_KEY` | (empty) | Your Claude API key, starting with `sk-ant-`. Required only when `AI_DRIVER=claude`. See [Using Claude instead](#using-claude-instead). |
| `AI_MODEL` | `claude-opus-5-5` | Any Claude model ID. |
| `AI_EFFORT` | `low` | How much the model reasons before answering (`low`, `medium`, `high`). |
| `AI_MAX_TOKENS` | `2048` | Output token limit per request. |
| `AI_TIMEOUT` | `30` | Seconds before an AI request is abandoned. |
| `AI_MAX_RETRIES` | `2` | The SDK retries rate limits, timeouts and 5xx errors this many times. |

`AI_MODEL` through `AI_MAX_RETRIES` apply only to Claude.

Settings, including the API key, are read only in [`config/ai.php`](config/ai.php); application code reads `config('ai.*')` and never calls `env()`. `.env` is gitignored, and `.env.example` holds the variable names without secrets. If `AI_DRIVER=claude` and the key is missing, the API returns a clear 503 rather than failing on the first AI call.

### Using Claude instead

Claude follows the prompt's rules (length, placeholders, signature) more reliably. To use it:

1. Sign in to the Claude Console and open [platform.claude.com/settings/keys](https://platform.claude.com/settings/keys). This is separate from claude.ai, and a claude.ai subscription does not include API usage.
2. Click **Create key**. Set **Linked account** to yourself, and select a **workspace** (for example Default). A key without a workspace needs an extra header this project does not send.
3. Add credits under **Settings → Billing**. Each request costs roughly $0.005–0.02 with `claude-opus-5-5`.
4. In `.env`, set `AI_DRIVER=claude` and paste the key (shown only once) into `ANTHROPIC_API_KEY`.

The prompt, JSON schema and logging are the same for both models; only the driver changes.

### Run it

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

**Response `200 OK`** (real output from llama3.2 through Ollama)

```json
{
  "subject": "Update on Your Order Delivery",
  "body": "Dear Priya,\n\nWe regret to inform you that your order will be delayed by 2 business days due to an unexpected shipping issue. We take full responsibility for the inconvenience this may cause and are working diligently to resolve the matter as quickly as possible.\n\nOur team is processing a revised delivery schedule, and we will be in touch with you shortly to confirm the new estimated delivery date. If you have any questions or concerns, please don't hesitate to reach out to us.\n\nWe appreciate your patience and understanding in this matter.\n\nBest regards,\n[Your Name]",
  "tone": "apologetic",
  "meta": {
    "model": "llama3.2",
    "ai_latency_ms": 2746,
    "request_id": "aea89447-c6b5-42bd-8103-975e043610a7"
  }
}
```

Response headers:
- `X-Request-Id`: the request ID. A client can send its own (8–64 letters, digits or dashes) and it will be reused.
- `X-Response-Time`: total time spent handling the request, for example `2756ms`.

### Errors

Every error is JSON with a `message`. AI failures also carry an `error` code.

| Status | When | Example body |
|---|---|---|
| 422 | A field is missing or invalid | `{"message": "The tone must be one of: formal, friendly, apologetic, persuasive, neutral.", "errors": {"tone": ["..."]}}` |
| 429 | More than 30 requests per minute from one client | `{"message": "Too Many Attempts."}` |
| 502 | Ollama is not running, the Ollama model is not installed, or the AI returned an error or an unusable response (such as an empty body) | `{"message": "Ollama model [llama3.2] is not installed. Run: ollama pull llama3.2", "error": "ai_model_not_found"}` |
| 503 | Claude only: the API is rate-limiting us (sends `Retry-After`), or no API key is configured | `{"message": "AI service is not configured. Set ANTHROPIC_API_KEY, or use AI_DRIVER=ollama to run a local model.", "error": "ai_not_configured"}` |
| 504 | The model did not answer within `OLLAMA_TIMEOUT` (Ollama) or `AI_TIMEOUT` (Claude) | `{"message": "AI service timed out.", "error": "ai_timeout"}` |

### Postman collection

[`postman/email-template-service.postman_collection.json`](postman/email-template-service.postman_collection.json) has three folders:
- **Generate:** one request per tone, plus one that sends its own `X-Request-Id` so you can find it in `ai.log`.
- **Validation errors:** requests that must return 422.
- **Diagnostics:** checks that the service is up, and that Ollama is running and has the model.

Each request has automatic checks:
- the response shape and the requested tone;
- that `meta.request_id` matches the `X-Request-Id` header;
- that `X-Response-Time` is present;
- two of the prompt's rules: the body greets the recipient by name, and is 80–150 words long.

**In Postman:**
1. Click **Import** and select the `.json` file.
2. If the service is not on port 8000, change the collection's `baseUrl` variable.
3. Send a request and see the **Test Results** tab, or use **Run collection** to run them all. **View → Show Postman Console** prints the AI latency for each request.

Import into a regular workspace. A workspace linked to a local folder converts the file into its own YAML format and removes the `.json`.

**From the command line** (needs Node.js):

```bash
npx newman run postman/email-template-service.postman_collection.json
```

The prompt-rule checks measure the model's output, not the service. With the default llama3.2 model, some emails come out under 80 words, so the word-count check can fail; Claude follows the length rule more reliably.

## Response-time logs

Each AI call writes one line to `storage/logs/ai.log`. The service takes a timestamp with `hrtime(true)` before and after the call and logs the difference in milliseconds ([`app/Services/EmailTemplateService.php`](app/Services/EmailTemplateService.php)).

Real lines from testing with llama3.2. The first is the response shown above; the second is a call where the model returned an empty body:

```
[2026-10-26 11:11:52] local.INFO: ai.call {"status":"ok","model":"llama3.2","tone":"apologetic","latency_ms":2746,"input_tokens":330,"output_tokens":138} {"request_id":"aea89447-c6b5-42bd-8103-975e043610a7"}
[2026-10-26 07:32:44] local.WARNING: ai.call {"status":"error","error":"ai_invalid_response","tone":"friendly","latency_ms":1562} {"request_id":"postman-demo-0001"}
```

- Failed calls are logged too, with the error code and the time spent before the failure. This includes calls where the model answered but the reply was unusable, such as an empty body (`"error":"ai_invalid_response"`).
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

**6. Structured output.** The request includes a JSON Schema with exactly two fields, `subject` and `body` (Ollama's `format` field, or Claude's `output_config.format`). The model is constrained to that shape, so there's no free text to parse. The service still rejects an empty subject or body with a 502; llama3.2 produced one in testing.

**7. One prompt for both models.** Ollama (default) and Claude receive exactly the same system prompt, user prompt and schema, so you can compare them directly by changing `AI_DRIVER`. Claude-only settings:
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
        ├── OllamaClient.php                 Local model through Ollama's HTTP API (default)
        ├── ClaudeClient.php                 Anthropic SDK implementation
        ├── FakeAiClient.php                 Offline implementation for tests and demos
        └── AiResult.php
config/ai.php                                AI settings, read from .env
config/logging.php                           "ai" log channel → storage/logs/ai.log
routes/api.php                               POST /api/email-templates
```

The controller only validates input and returns JSON. The service holds the logic and depends on the `AiClient` interface rather than on any one provider. Switching between Ollama and Claude is a config change, and adding a provider is one new class plus one line in `AppServiceProvider`, with no change to the service, controller, prompt or logging.

## Tests

```bash
php artisan test
```

The tests use the fake driver, so they need neither Ollama nor an API key. They cover:
- the response shape and headers;
- the prompt's content;
- the log line for successful and failed AI calls (status, tone, tokens, latency and request ID);
- validation, including tone values and injection attempts in the name;
- request-ID reuse;
- error mapping;
- the Ollama driver against faked HTTP responses: request body, reply and token parsing, Ollama not running, timeout, missing model and invalid JSON.
