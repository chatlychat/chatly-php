<?php

declare(strict_types=1);

namespace Chatly\Resources;

/**
 * AI configuration — provider keys, spend, tone of voice, custom tools.
 *
 * `$chatly->ai->…`
 *
 * ─── THREE URL PREFIXES, ONE NAMESPACE ───────────────────────────────────
 *
 * Provider credentials hang off `/v1/ai-providers`, everything else off
 * `/v1/ai/…`. They are grouped here because they answer one question — how
 * does this workspace's AI behave — and split in the API because the
 * credential routes predate the rest.
 *
 * Nothing here returns a provider key, a prefix of one, or a hash of one.
 * `keyHint` on a provider row is four characters and is the whole of what any
 * response carries about a stored key.
 */
final class Ai extends AbstractResource
{
    /**
     * `GET /v1/ai-providers` — every SUPPORTED provider, configured or not.
     *
     * Not "the providers you have set up": the response is the full registry
     * with a `configured` flag per row, so a settings screen can offer the
     * ones that are missing. Read `configured`, not the length of the array.
     *
     * `{ providers: [...] }` — an object wrapping the list, not a bare array.
     */
    public function providers(): mixed
    {
        return $this->httpGet('/v1/ai-providers');
    }

    /**
     * `POST /v1/ai-providers` — store a provider key, or replace the stored one.
     *
     * **The key is validated against the provider before it is saved**, by a
     * real call, so this is slower than a write and can fail for reasons that
     * are not your request's fault. Only an authentication-class ANSWER
     * refuses the save; a provider outage stores the key as `unverified`,
     * which is a state and not an error — read `validation.status` rather
     * than treating a 201 as "this key works".
     *
     * The response also carries `reindexQueued`: how many KB articles this
     * install queued for embedding, which is zero for every provider but
     * OpenAI and is CAPPED. Past the cap the rest index on their next
     * publish, so the number is reported rather than being "all of them".
     *
     * @param string $provider One of the registry's provider keys — see
     *                         {@see providers()} rather than guessing.
     * @param string $apiKey   The provider's own credential.
     */
    public function addProvider(string $provider, string $apiKey): mixed
    {
        return $this->httpPost('/v1/ai-providers', ['provider' => $provider, 'apiKey' => $apiKey]);
    }

    /**
     * `POST /v1/ai-providers/{provider}/test` — re-check the STORED key.
     *
     * Takes no body: it tests what is already saved, so there is nothing to
     * send. Use it to tell "the key was revoked at the provider" apart from
     * "our call failed" after an AI feature starts erroring — a 201 here with
     * `validation.status` of `ok` means the credential is still good.
     */
    public function testProvider(string $provider): mixed
    {
        return $this->httpPost('/v1/ai-providers/' . self::seg($provider, 'provider') . '/test');
    }

    /**
     * `GET /v1/ai/quota` — month-to-date AI spend against the plan cap.
     *
     * Spend, cap and a projection to month-end. The projection is the field
     * worth alerting on: a cap is hit at a moment, and the number that tells
     * you it is coming is not the one that tells you it arrived.
     *
     * Needs `workspace:billing:read`, which is an owner-level scope — a key
     * scoped for AI work alone cannot read this.
     */
    public function quota(): mixed
    {
        return $this->httpGet('/v1/ai/quota');
    }

    /**
     * `GET /v1/ai/tone` — the workspace's tone-of-voice rules.
     *
     * A BARE ARRAY, ordered category then position, capped at 40 rules (8 per
     * category across 5 categories) and `@PaginationExempt` — there is no
     * cursor and no walker, because the whole set always fits in one answer.
     */
    public function tone(): mixed
    {
        return $this->httpGet('/v1/ai/tone');
    }

    /**
     * `PUT /v1/ai/tone` — REPLACE the whole rule set.
     *
     * PUT, and a replace rather than a patch: send every rule you want to
     * keep. Omitting one deletes it. That is the API's design, not this
     * method's shortcut — the settings screen edits the list as a list, so
     * there is no per-rule id to patch and no delete call per removal.
     *
     * Answers with the set as STORED, re-read after the write, as the same
     * bare array {@see tone()} returns — so a caller can see what the
     * per-category cap did to an over-long submission rather than assuming
     * its own input landed.
     *
     * @param array<int,array<string,mixed>> $rules Each `category` (one of the
     *                                              five), `rule` (3–… chars,
     *                                              trimmed) and `enabled`
     *                                              (defaults true). At most 8
     *                                              per category; the write is
     *                                              refused rather than
     *                                              silently trimmed, because
     *                                              a trimmed rule is one the
     *                                              operator believes is live.
     */
    public function setTone(array $rules): mixed
    {
        return $this->httpPut('/v1/ai/tone', ['rules' => array_values($rules)]);
    }

    /**
     * `GET /v1/ai/tone/preview` — the rendered block, as prompts receive it.
     *
     * `{ instruction: "…" }` — the exact text spliced into every
     * customer-facing system prompt. **An EMPTY string is the normal answer
     * for a workspace that has configured nothing**: no default house style
     * is invented, so an empty instruction means "no tone applied" and not
     * "something went wrong".
     */
    public function tonePreview(): mixed
    {
        return $this->httpGet('/v1/ai/tone/preview');
    }

    /**
     * `POST /v1/ai/tone/generate` — propose rules from a pasted style guide.
     *
     * **Proposes. Does NOT save.** The response is a draft for a person to
     * approve; nothing changes until you hand the rules you want to
     * {@see setTone()}. It answers 201 even so — Nest answers a POST with 201
     * absent an explicit code, and the status is part of the contract even
     * though nothing was created.
     *
     * @param string $styleGuide 40–20,000 characters, trimmed. Shorter is a
     *                           400: there is nothing to infer a voice from.
     */
    public function generateTone(string $styleGuide): mixed
    {
        return $this->httpPost('/v1/ai/tone/generate', ['styleGuide' => $styleGuide]);
    }

    /**
     * `GET /v1/ai/tools` — the workspace's custom tools.
     *
     * A bare array, `@PaginationExempt`. **Secret header VALUES are omitted**
     * from every row — only the header names come back, so this cannot be
     * used to read back a credential you stored.
     */
    public function tools(): mixed
    {
        return $this->httpGet('/v1/ai/tools');
    }

    /**
     * `POST /v1/ai/tools` — define a tool an AI agent may call.
     *
     * @param array<string,mixed> $tool `name`, `description` (1–1000),
     *                                  `urlTemplate` (≤2048), `method`
     *                                  (defaults `GET`), `headers`, and
     *                                  `secretHeaders` — which are sent on
     *                                  write and never returned on read.
     *                                  A reserved header name is a 400: a few
     *                                  are ours, and they are how your
     *                                  endpoint knows the call came from us
     *                                  and who it is for.
     */
    public function createTool(array $tool): mixed
    {
        return $this->httpPost('/v1/ai/tools', $tool);
    }

    /**
     * `PATCH /v1/ai/tools/{id}` — partial update.
     *
     * ─── `secretHeaders` HAS THREE STATES, NOT TWO ────────────────────────
     *
     * OMIT the key to leave the stored secrets alone; send `null` to clear
     * them; send a map to replace them. The distinction is the whole reason
     * the field is nullable: a settings form that round-trips an empty value
     * instead of omitting the key wipes the API key every time somebody edits
     * the description.
     *
     * @param array<string,mixed> $changes Any subset of the create fields.
     */
    public function updateTool(string $id, array $changes): mixed
    {
        return $this->httpPatch('/v1/ai/tools/' . self::seg($id), $changes);
    }

    /**
     * `DELETE /v1/ai/tools/{id}`.
     *
     * Answers **200 with `{ deleted: true }`**, not a 204 — so a caller can
     * tell a completed delete from a response it failed to read. Bots that
     * listed this tool in `enabledTools` simply stop being able to call it.
     */
    public function deleteTool(string $id): mixed
    {
        return $this->httpDelete('/v1/ai/tools/' . self::seg($id));
    }
}
