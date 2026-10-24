// Read-only B2 owner-operated Mac capability inventory; not owner authentication.
// No key creation, Keychain access, signed claims, secret input, network or GO.
// This tool checks only whether a candidate device could be assessed later.
// The result must NEVER be treated as permission to invoke the live B1 adapter.
import Foundation
import CryptoKit
import LocalAuthentication

let enclaveAvailable = SecureEnclave.isAvailable

// canEvaluatePolicy only queries the availability of enrolled biometrics.
// It does not prompt for or authenticate a person, authorize a signature,
// prove key custody, or make a revocation or replay guarantee.
let context = LAContext()
var policyError: NSError?
let biometricsCanBeEvaluated = context.canEvaluatePolicy(
    .deviceOwnerAuthenticationWithBiometrics, error: &policyError
)
context.invalidate()

let report: [String: Any] = [
    "kind": "E858_B2_MAC_LOCAL_CAPABILITY_REVIEW_V0",
    "secure_enclave_available": enclaveAvailable,
    "biometric_policy_evaluable": biometricsCanBeEvaluated,
    "signing_key_created": false,
    "owner_identity_verified": false,
    "fresh_owner_consent_verified": false,
    "independent_authority_verified": false,
    "revocation_verified": false,
    "durable_one_shot_verified": false,
    "execution_allowed": false,
    "real_aiven_connected": false,
    "production_go": false
]

do {
    let bytes = try JSONSerialization.data(
        withJSONObject: report, options: [.sortedKeys]
    )
    guard let line = String(data: bytes, encoding: .utf8) else {
        fatalError("Fixed output encoding unavailable")
    }
    print(line)
} catch {
    // No error details from macOS are emitted. Fail closed.
    print("{\"kind\":\"E858_B2_MAC_CAPABILITY_PROBE_FAILED\",\"execution_allowed\":false}")
    exit(1)
}
