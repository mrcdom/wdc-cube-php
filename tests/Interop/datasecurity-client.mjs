// Repete, com WebCrypto (a mesma API do browser), os passos de DataSecurity.ts do shell React:
//   pwd    = base64url(12 bytes aleatórios)             (senha da sessão, como texto)
//   key    = PBKDF2-SHA256(pwd, salt[16], 250000) → AES-256-GCM
//   secret = base36(RSA_e,n(bigint(UTF8(base64(UTF8(pwd)))))) . b64url(salt) . b64url(iv[12])
// Uso: node datasecurity-client.mjs '<e:n base36>' '<texto para cifrar>' ['<base64 para decifrar>' '<secret anterior>' '<pwd anterior>']
// Saída: JSON { secret, pwd, ciphered, deciphered? }
import { webcrypto as crypto } from 'node:crypto'

const [, , appSKey, plain, toDecipher, prevSecret, prevPwd] = process.argv
const subtle = crypto.subtle
const enc = new TextEncoder()
const b64 = (u8) => Buffer.from(u8).toString('base64')
const b64url = (u8) => b64(u8).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
const fromB64url = (s) => new Uint8Array(Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64'))
const bufToBigint = (u8) => u8.reduce((acc, b) => (acc << 8n) + BigInt(b), 0n)
const modPow = (b, e, m) => { let r = 1n; b %= m; while (e > 0n) { if (e & 1n) r = (r * b) % m; e >>= 1n; b = (b * b) % m } return r }
const parse36 = (s) => [...s].reduce((acc, c) => acc * 36n + BigInt(parseInt(c, 36)), 0n)

async function deriveKey(pwdBuf, salt) {
  const raw = await subtle.importKey('raw', pwdBuf, { name: 'PBKDF2' }, false, ['deriveKey'])
  return subtle.deriveKey({ name: 'PBKDF2', salt, iterations: 250000, hash: 'SHA-256' }, raw,
    { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
}

let secret, pwd, key, iv
if (prevSecret) {
  secret = prevSecret; pwd = prevPwd
  const [, saltB, ivB] = prevSecret.split('.')
  iv = fromB64url(ivB)
  key = await deriveKey(enc.encode(pwd), fromB64url(saltB))
} else {
  const [e, n] = appSKey.split(':').map(parse36)
  pwd = b64url(crypto.getRandomValues(new Uint8Array(12)))
  const pwdBuf = enc.encode(pwd)
  const salt = crypto.getRandomValues(new Uint8Array(16))
  iv = crypto.getRandomValues(new Uint8Array(12))
  key = await deriveKey(pwdBuf, salt)
  const m = bufToBigint(enc.encode(b64(pwdBuf)))
  secret = `${modPow(m, e, n).toString(36)}.${b64url(salt)}.${b64url(iv)}`
}
const out = { secret, pwd }
out.ciphered = b64(new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv }, key, enc.encode(plain))))
if (toDecipher) {
  const data = new Uint8Array(Buffer.from(toDecipher, 'base64'))
  out.deciphered = new TextDecoder().decode(await subtle.decrypt({ name: 'AES-GCM', iv }, key, data))
}
console.log(JSON.stringify(out))
