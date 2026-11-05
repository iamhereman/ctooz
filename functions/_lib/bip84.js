import { HDKey } from '@scure/bip32';
import { sha256 } from '@noble/hashes/sha2.js';
import { ripemd160 } from '@noble/hashes/legacy.js';
import { bech32 } from '@scure/base';

/**
 * Derives a Native SegWit (BIP84 / P2WPKH) Bitcoin address (bc1q...) from an xpub key
 * @param {string} xpub Extended public key
 * @param {number} index Derivation index (0, 1, 2, ...)
 * @param {number} change 0 for external receive addresses
 * @returns {string} Native SegWit Bitcoin address (bc1q...)
 */
export function deriveBip84Address(xpub, index, change = 0) {
    const root = HDKey.fromExtendedKey(xpub);
    const child = root.deriveChild(change).deriveChild(index);
    const pubKey = child.publicKey; // 33 bytes compressed
    
    // Hash160 = RIPEMD160(SHA256(pubKey))
    const h160 = ripemd160(sha256(pubKey));
    
    // Convert 8-bit to 5-bit words for bech32
    const words = bech32.toWords(h160);
    // Prepend witness version 0 (P2WPKH)
    words.unshift(0);
    
    return bech32.encode('bc', words);
}
