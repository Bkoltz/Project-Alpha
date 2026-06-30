# Client Onboarding Portal

Project Alpha includes an invitation-only client onboarding workflow. Authorized
users manage invitations from **Clients > Onboarding**.

## Workflow

1. A PA user creates an invitation for an email address.
2. The invitation may target an existing client or a new standalone client.
3. Assigning the new client to an organization is optional.
4. PA can email the link, or the user can copy and deliver it manually.
5. The recipient requests and enters a short-lived email verification code.
6. The recipient submits contact and billing-profile information.
7. PA stores the submission as a proposal until an authorized user approves or rejects it.

## Security Controls

- Invitation tokens are random, stored only as SHA-256 hashes, expire, and are single-use.
- Verification codes are password-hashed, expire after 15 minutes, and are revoked after five failed attempts.
- Public endpoints use CSRF protection and fail-closed rate limiting.
- Public responses do not offer client or email lookup.
- Every invitation is scoped to the active PA organization for review authorization.
- Submitted fields are strictly allowlisted and length-limited.
- Existing client records are not changed before approval.
- Invitation, verification, submission, approval, rejection, and revocation events are audited.
- The portal does not collect card or payment credentials and is separate from AutoPay.
