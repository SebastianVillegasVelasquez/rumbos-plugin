# The Rumbos service's golden signing vectors

Byte-for-byte copies of `contracts/signing-vectors/{jwks,result,result-failed,attempt,definition}.json` of the `rumbos-backend`
repository (added there by commit `0f04398`, the same on its `main`; `result-failed.json` by `02e1e35`, on its branch
`fix/journey-testability`). The service makes them with its
production signing code (`tests/signing_vectors.py` calls `app.passback.statement.claims_for` and `sign`), and its
own tests check them with `cryptography` alone. **Nothing here was made or changed by this plugin.** Do not edit
them: copy them again when the service changes them.

- The key is the vectors' own test key. It is not the production key.
- The exam in `definition.json` and `attempt.json` is made up (`form_id` `synthetic`).
- `sub` is `s-4f9d2c`, which is **not** a user reference a real launch can carry (32 lower case hexadecimal
  characters). So a vector verifies as signed, and is then refused for its `sub`. The tests check exactly that.
