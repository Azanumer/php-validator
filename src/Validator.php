<?php
/**
 * Validator — dependency-free server-side input validation for PHP 7.4+.
 *
 *   $v = new Validator($_POST, [
 *       'name'              => 'required|min:3|max:60',
 *       'email'             => 'required|email',
 *       'password'          => 'required|min:8|confirmed',
 *       'age'               => 'integer|min:18|max:120',
 *       'website'           => 'nullable|url',
 *       'plan'              => 'required|in:free,pro,team',
 *       'tags'              => 'array|max:5',
 *       'avatar'            => 'nullable|file|mimes:jpg,png,webp|max_kb:2048',
 *   ]);
 *
 *   if ($v->fails()) {
 *       foreach ($v->errors() as $field => $messages) { ... }
 *   }
 *   $clean = $v->validated();   // only fields that passed
 *
 * Nested fields use dot notation: 'address.city' => 'required'.
 * String rules can be mixed with closures: ['required', fn($v) => ...] returning
 * true (pass) or a string (custom message).
 */
class Validator
{
    /** @var array Raw input data. */
    private $data;
    /** @var array Field => list of rules (string|callable). */
    private $rules;
    /** @var array Custom messages: 'field.rule' => message. */
    private $messages;
    /** @var array Collected errors: field => [messages]. */
    private $errors = [];
    /** @var bool Whether validate() has run. */
    private $validated = false;

    /**
     * @param array $data     Input data (e.g. $_POST; files passed via 'file' rules read $_FILES internally).
     * @param array $rules    Field => 'rule1|rule2:param' string or [rules...] array.
     * @param array $messages Optional custom messages keyed 'field.rule' or 'field.*'.
     */
    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data     = $data;
        $this->rules    = $this->normalizeRules($rules);
        $this->messages = $messages;
    }

    /** Run validation; returns true when everything passes. */
    public function passes(): bool
    {
        $this->validate();
        return empty($this->errors);
    }

    /** Run validation; returns true when something fails. */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /** field => [message, ...] */
    public function errors(): array
    {
        $this->validate();
        return $this->errors;
    }

    /** First error message for a field, or null. */
    public function first(string $field): ?string
    {
        $errors = $this->errors();
        return isset($errors[$field][0]) ? $errors[$field][0] : null;
    }

    /** Input limited to fields that passed validation. */
    public function validated(): array
    {
        $this->validate();
        $out = [];
        foreach ($this->rules as $field => $rules) {
            if (!isset($this->errors[$field])) {
                $out[$field] = $this->value($field);
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    private function normalizeRules(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $rule) {
            $out[$field] = is_array($rule) ? $rule : explode('|', (string) $rule);
        }
        return $out;
    }

    /** Read a (possibly nested, dot-notation) value; $exists set by reference. */
    private function value(string $field, &$exists = null)
    {
        $parts = explode('.', $field);
        $node  = $this->data;
        foreach ($parts as $part) {
            if (is_array($node) && array_key_exists($part, $node)) {
                $node = $node[$part];
            } else {
                $exists = false;
                return null;
            }
        }
        $exists = true;
        return $node;
    }

    private function validate(): void
    {
        if ($this->validated) {
            return;
        }
        $this->validated = true;

        foreach ($this->rules as $field => $rules) {
            $value = $this->value($field, $exists);
            $hasValue = $exists && $value !== null && $value !== '';

            // 'nullable' short-circuits everything else when empty.
            if (!$hasValue && $this->hasRule($rules, 'nullable')) {
                continue;
            }

            foreach ($rules as $rule) {
                if (is_callable($rule)) {
                    $result = $rule($value, $field, $this->data);
                    if ($result !== true) {
                        $this->addError($field, 'custom', is_string($result) ? $result : 'is invalid.');
                    }
                    continue;
                }
                [$name, $params] = $this->parseRule($rule);
                if ($name === 'nullable') {
                    continue; // handled above
                }
                // Non-required rules are skipped for empty values.
                if (!$hasValue && $name !== 'required') {
                    continue;
                }
                $method = 'check' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
                if (method_exists($this, $method)) {
                    $ok = $this->$method($field, $value, $params, $exists);
                    if ($ok !== true) {
                        $this->addError($field, $name, is_string($ok) ? $ok : null, $params);
                        // Stop at first failure per field for 'required' to avoid noise.
                        if ($name === 'required') {
                            break;
                        }
                    }
                }
            }
        }
    }

    private function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if (!is_callable($rule) && $this->parseRule($rule)[0] === $name) {
                return true;
            }
        }
        return false;
    }

    private function parseRule($rule): array
    {
        $rule = (string) $rule;
        $pos = strpos($rule, ':');
        if ($pos === false) {
            return [$rule, []];
        }
        return [substr($rule, 0, $pos), explode(',', substr($rule, $pos + 1))];
    }

    private function addError(string $field, string $rule, ?string $custom, array $params = []): void
    {
        $key = $field . '.' . $rule;
        if (isset($this->messages[$key])) {
            $message = $this->messages[$key];
        } elseif (isset($this->messages[$field . '.*'])) {
            $message = $this->messages[$field . '.*'];
        } elseif ($custom !== null) {
            $message = $custom;
        } else {
            $message = $this->defaultMessage($rule, $params);
        }
        $message = str_replace([':field', ':attribute'], $this->prettyField($field), $message);
        foreach ($params as $i => $p) {
            $message = str_replace(':param' . ($i + 1), $p, $message);
        }
        $this->errors[$field][] = $message;
    }

    private function prettyField(string $field): string
    {
        return str_replace(['.', '_'], ' ', $field);
    }

    private function defaultMessage(string $rule, array $params): string
    {
        switch ($rule) {
            case 'required':  return 'The :field field is required.';
            case 'email':     return 'The :field must be a valid email address.';
            case 'url':       return 'The :field must be a valid URL.';
            case 'numeric':   return 'The :field must be a number.';
            case 'integer':   return 'The :field must be an integer.';
            case 'boolean':   return 'The :field must be true or false.';
            case 'array':     return 'The :field must be an array.';
            case 'alpha':     return 'The :field may only contain letters.';
            case 'alpha_num': return 'The :field may only contain letters and numbers.';
            case 'min':       return 'The :field must be at least :param1 characters / items / value.';
            case 'max':       return 'The :field may not be greater than :param1 characters / items / value.';
            case 'in':        return 'The selected :field is invalid.';
            case 'not_in':    return 'The selected :field is invalid.';
            case 'regex':     return 'The :field format is invalid.';
            case 'confirmed': return 'The :field confirmation does not match.';
            case 'same':      return 'The :field and :param1 must match.';
            case 'different': return 'The :field and :param1 must be different.';
            case 'date':      return 'The :field is not a valid date.';
            case 'file':      return 'The :field must be a file upload.';
            case 'mimes':     return 'The :field must be a file of type: :param1.';
            case 'max_kb':    return 'The :field may not be greater than :param1 kilobytes.';
            default:          return 'The :field is invalid.';
        }
    }

    /* ------------------------------ checks ------------------------------ */

    private function checkRequired(string $field, $value, array $params, bool $exists)
    {
        if (!$exists || $value === null || $value === '' || $value === []) {
            return 'The :field field is required.';
        }
        return true;
    }

    private function checkEmail(string $field, $value, array $params, bool $exists)
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? true : false;
    }

    private function checkUrl(string $field, $value, array $params, bool $exists)
    {
        return filter_var($value, FILTER_VALIDATE_URL) ? true : false;
    }

    private function checkNumeric(string $field, $value, array $params, bool $exists)
    {
        return is_numeric($value) ? true : false;
    }

    private function checkInteger(string $field, $value, array $params, bool $exists)
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? true : false;
    }

    private function checkBoolean(string $field, $value, array $params, bool $exists)
    {
        return in_array($value, [true, false, 0, 1, '0', '1'], true) ? true : false;
    }

    private function checkArray(string $field, $value, array $params, bool $exists)
    {
        return is_array($value) ? true : false;
    }

    private function checkAlpha(string $field, $value, array $params, bool $exists)
    {
        return is_string($value) && preg_match('/^\p{L}+$/u', $value) ? true : false;
    }

    private function checkAlphaNum(string $field, $value, array $params, bool $exists)
    {
        return is_string($value) && preg_match('/^[\p{L}\p{N}]+$/u', $value) ? true : false;
    }

    /** Size-aware: strings → length, arrays → count, numbers → value. */
    private function size($value)
    {
        if (is_array($value)) {
            return count($value);
        }
        if (is_numeric($value) && !is_string($value)) {
            return $value + 0;
        }
        if (is_numeric($value)) {
            // numeric strings compare as values for min/max, consistent with most frameworks
            return $value + 0;
        }
        return mb_strlen((string) $value);
    }

    private function checkMin(string $field, $value, array $params, bool $exists)
    {
        return $this->size($value) >= ($params[0] + 0) ? true : false;
    }

    private function checkMax(string $field, $value, array $params, bool $exists)
    {
        return $this->size($value) <= ($params[0] + 0) ? true : false;
    }

    private function checkIn(string $field, $value, array $params, bool $exists)
    {
        return in_array((string) $value, $params, true) ? true : false;
    }

    private function checkNotIn(string $field, $value, array $params, bool $exists)
    {
        return !in_array((string) $value, $params, true) ? true : false;
    }

    private function checkRegex(string $field, $value, array $params, bool $exists)
    {
        $pattern = $params[0] ?? '';
        return $pattern !== '' && preg_match($pattern, (string) $value) ? true : false;
    }

    private function checkConfirmed(string $field, $value, array $params, bool $exists)
    {
        $other = $this->value($field . '_confirmation', $ok);
        return $ok && $other === $value ? true : false;
    }

    private function checkSame(string $field, $value, array $params, bool $exists)
    {
        $other = $this->value($params[0] ?? '', $ok);
        return $ok && $other === $value ? true : false;
    }

    private function checkDifferent(string $field, $value, array $params, bool $exists)
    {
        $other = $this->value($params[0] ?? '', $ok);
        return $ok && $other !== $value ? true : false;
    }

    private function checkDate(string $field, $value, array $params, bool $exists)
    {
        return strtotime((string) $value) !== false ? true : false;
    }

    /* --------------------------- file checks ---------------------------- */

    /** Resolve an upload entry: $_FILES-shaped array or a real path passed in data. */
    private function uploadInfo(string $field)
    {
        if (isset($_FILES[$field]) && is_array($_FILES[$field])) {
            return $_FILES[$field];
        }
        $value = $this->value($field);
        if (is_array($value) && isset($value['tmp_name'])) {
            return $value;
        }
        return null;
    }

    private function checkFile(string $field, $value, array $params, bool $exists)
    {
        $info = $this->uploadInfo($field);
        if ($info === null || ($info['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        return true;
    }

    private function checkMimes(string $field, $value, array $params, bool $exists)
    {
        $info = $this->uploadInfo($field);
        if ($info === null || !is_file($info['tmp_name'])) {
            return false;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($info['tmp_name']);
        $allowed = [];
        foreach ($params as $ext) {
            $ext = strtolower(trim($ext));
            $map = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                'pdf' => 'application/pdf', 'zip' => 'application/zip',
                'csv' => 'text/csv', 'txt' => 'text/plain',
            ];
            $allowed[] = $map[$ext] ?? $ext;
        }
        return in_array($mime, $allowed, true) ? true : false;
    }

    private function checkMaxKb(string $field, $value, array $params, bool $exists)
    {
        $info = $this->uploadInfo($field);
        if ($info === null || !isset($info['size'])) {
            return false;
        }
        return ($info['size'] / 1024) <= ($params[0] + 0) ? true : false;
    }
}
