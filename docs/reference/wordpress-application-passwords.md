# WordPress Application Passwords

Reference for creating, transmitting and — rarely — verifying a WordPress
application password. Written after a verification bug burned two credential
rotations in a sibling repository; the method below is the one core itself uses.

## The Hashing Change In 6.8

Since WordPress 6.8 an application password is hashed with `wp_fast_hash()`,
which produces a `$generic$`-prefixed value. Passwords created before 6.8 are
phpass hashes. The two are not interchangeable, and this is the part that bites:

**`wp_check_password()` cannot verify a `$generic$` hash.** It returns `false`
for a valid password, silently — no warning, no error, no clue. A checker built
on it reports a false negative that looks exactly like a wrong credential.

## What This Plugin Does, And Why It Is Right

The enrollment services do not verify application passwords at all. They

1. create one with `WP_Application_Passwords::create_new_application_password()`
   — see `addons/agency-control/src/Enrollment/EnrollmentService.php`;
2. transmit it as HTTP Basic, `base64_encode( $username . ':' . $secret )`;
3. let the receiving site's own authentication stack do the checking;
4. revoke with `WP_Application_Passwords::delete_application_password()`.

That is the pattern to keep. It is immune to the hashing change entirely,
because the only code comparing a password to a hash is core's. Prefer it for
anything new.

## If You Must Verify Locally

Only when the question is genuinely "does this stored value still correspond to
a registered entry" — a diagnostic, not an auth flow. Use core's dispatcher,
which routes `$generic$` hashes to `wp_verify_fast_hash()` and older ones to
`wp_check_password()`:

```php
// Core strips everything but alphanumerics; case is significant.
$raw = preg_replace( '/[^a-z\d]/i', '', $candidate );

foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $entry ) {
    if ( WP_Application_Passwords::check_password( $raw, $entry['password'] ) ) {
        // $entry['name'] is the entry it belongs to.
    }
}
```

Never reimplement the comparison. `WP_Application_Passwords::check_password()`
exists precisely so callers do not have to know which hash family they have.

## Prove The Checker Before Reporting A Negative

A check that says "does not match" is a claim about someone's credential. Before
making it, mint a throwaway and confirm the checker accepts a known-good value:

```php
list( $raw, $item ) = WP_Application_Passwords::create_new_application_password(
    $user_id,
    array( 'name' => 'Method self check' )
);

// Must be true. If it is false, the checker is wrong, not the credential.
WP_Application_Passwords::check_password(
    preg_replace( '/[^a-z\d]/i', '', $raw ),
    $item['password']
);

WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );
```

## Handling

A normal user password is a different thing:
`wp_check_password( $raw, $user->user_pass, $user->ID )` is correct for that and
always has been.

Never write a credential value into tracked files, commit messages, logs, test
fixtures, or terminal output. Keep machine-local credential files owner-readable
only (`0600`) and gitignored.
