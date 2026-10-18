<?php
/**
 * Demo: php-validator on a fake signup payload.
 *
 * Run: php examples/validate-example.php
 */
require __DIR__ . '/../src/Validator.php';

$payload = [
    'name'                  => 'Az',
    'email'                 => 'not-an-email',
    'password'              => 'secret123',
    'password_confirmation' => 'secret1234',
    'age'                   => 'seventeen',
    'website'               => '',
    'plan'                  => 'enterprise',
    'tags'                  => ['php', 'wordpress'],
    'address'               => ['city' => ''],
];

$validator = new Validator($payload, [
    'name'              => 'required|min:3|max:60',
    'email'             => 'required|email',
    'password'          => ['required', 'min:8', 'confirmed'],
    'age'               => 'required|integer|min:18',
    'website'           => 'nullable|url',
    'plan'              => 'required|in:free,pro,team',
    'tags'              => 'array|max:5',
    'address.city'      => 'required',
    'username'          => ['required', function ($value) {
        // Custom closure rule: return true to pass, a string to fail with that message.
        return preg_match('/^[a-z0-9_]{3,20}$/', (string) $value)
            ? true
            : 'Usernames may only contain lowercase letters, numbers and underscores.';
    }],
], [
    // Custom messages keyed "field.rule":
    'email.email' => 'Please enter a real email address, :field.',
]);

if ($validator->fails()) {
    echo "Validation FAILED:\n";
    foreach ($validator->errors() as $field => $messages) {
        foreach ($messages as $message) {
            echo "  - {$field}: {$message}\n";
        }
    }
    echo "\nFirst error for 'email': " . $validator->first('email') . "\n";
    exit(1);
}

echo "Validation PASSED. Clean data:\n";
print_r($validator->validated());
