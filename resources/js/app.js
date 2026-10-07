import { Passkeys, PasskeyError, UserCancelledError, PasskeyExistsError } from '@laravel/passkeys';

// Exposed so Blade/Alpine views can run the WebAuthn ceremonies.
window.Passkeys = Passkeys;
window.PasskeyErrors = { PasskeyError, UserCancelledError, PasskeyExistsError };
