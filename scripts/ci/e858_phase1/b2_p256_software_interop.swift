// EPHEMERAL SOFTWARE P-256 ONLY. No Secure Enclave or Keychain use.
// This isolated interop proof does not authenticate any Canovia owner,
// prompt for presence, generate real approval, or enable connection.
// All keys exist in volatile hosted-CI process memory, never persisted.
import Foundation
import CryptoKit

let message = "CANOVIA_E858_B2_SYNTHETIC_SOFTWARE_P256_INTEROP_V0"
do {
    let ephemeralSoftwareKey = P256.Signing.PrivateKey()
    let signature = try ephemeralSoftwareKey.signature(for: Data(message.utf8))
    func hex(_ data: Data) -> String {
        data.map { String(format: "%02x", $0) }.joined()
    }
    let result: [String: Any] = [
        "kind": "E858_B2_SYNTHETIC_SOFTWARE_P256_INTEROP_V0",
        "message": message,
        "public_key_x963_hex": hex(ephemeralSoftwareKey.publicKey.x963Representation),
        "signature_der_hex": hex(signature.derRepresentation),
        "secure_enclave_used": false,
        "key_persisted": false,
        "owner_authenticated": false,
        "execution_allowed": false,
        "production_go": false
    ]
    let json = try JSONSerialization.data(withJSONObject: result, options: [.sortedKeys])
    guard let output = String(data: json, encoding: .utf8) else {
        fatalError("Invalid synthetic JSON")
    }
    print(output)
} catch {
    fputs("SYNTHETIC_P256_SOFTWARE_INTEROP_BLOCK\n", stderr)
    exit(1)
}
