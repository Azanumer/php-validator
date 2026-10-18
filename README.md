# php-validator

Dependency-free server-side input validation for PHP 7.4+. No Composer, no
framework — one class, pipe-separated rules, custom closures, dot-notation
nested fields, and file-upload rules.

```php
$v = new Validator($_POST, [
    'name'     => 'required|min:3|max:60',
    'email'    => 'required|email',
    'password' => 'required|min:8|confirmed',
    'plan'     => 'required|in:free,pro,team',
    'website'  => 'nullable|url',
    'avatar'   => 'nullable|file|mimes:jpg,png,webp|max_kb:2048',
]);

if ($v->fails()) {
    foreach ($v->errors() as $field => $messages) { /* show them */ }
}
$clean = $v->validated(); // only fields that passed
```

## Rules

| Rule | Meaning |
|------|---------|
| `required` | Present and non-empty |
| `nullable` | Skip other rules when empty |
| `email`, `url` | `filter_var` validated |
| `numeric`, `integer`, `boolean`, `array` | Type checks |
| `alpha`, `alpha_num` | Unicode-aware letter/number checks |
| `min:n`, `max:n` | Size-aware: string length, array count, numeric value |
| `in:a,b`, `not_in:a,b` | Allow/deny lists |
| `regex:/.../` | Pattern match |
| `confirmed` | Matches `<field>_confirmation` |
| `same:other`, `different:other` | Cross-field comparison |
| `date` | Parseable date |
| `file` | Valid `$_FILES` upload entry |
| `mimes:jpg,png` | Real MIME sniffed via `finfo`, not extension |
| `max_kb:n` | Upload size cap |

Custom rules are closures returning `true` (pass) or a string (failure message).
Nested fields use dot notation: `'address.city' => 'required'`.
Custom messages keyed `'field.rule'` or `'field.*'`; placeholders `:field`,
`:param1`, `:param2` are interpolated.

## Files

- `src/Validator.php` — the whole library (one class)
- `examples/validate-example.php` — demo run (`php examples/validate-example.php`)

## License

MIT — see `LICENSE`.
