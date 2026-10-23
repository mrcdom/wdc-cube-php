# remote.bridge.react

TypeScript/React bridge for the **Remote Cube MVP** Host/Shell protocol. It implements the client (shell) side of the protocol in the browser — connects to the server over WebSocket (`/dispatcher/{appId}`, `wdc` subprotocol), performs the RSA/AES-GCM security handshake, manages ViewStates and provides React hooks so UI components can render states and emit events.

Used by `shopping.shell.react` (the shopping application) and any other React shell over the same protocol.

> Contains only TypeScript/React source code, with no build of its own: the shell imports it straight from source (alias `@wdc/remote-bridge-react` in the shell's `package.json`).

## Layout

```
src/
  bridge/
    ViewStateCoordinator.ts   # Manages the WS session, dispatches events, distributes ViewState updates
    ViewScope.ts              # React hook/context: exposes reactive ViewState to components
    DataSecurity.ts           # RSA + AES-GCM handshake (Web Crypto API)
    FlushRequestContext.ts    # Serializes and sends events to the host
    ReconnectController.ts    # Progressive backoff on WS reconnection
    ViewGarbageCollector.ts   # Cleans up released views
    types.ts                  # Shared types (HostResponse, ViewStateSnapshot, ...)
    constants.ts              # Protocol constants
    index.tsx                 # Public entry point of the bridge
  utils/
    RSA.ts                    # RSA OAEP encrypt/decrypt via Web Crypto API
    Base64.ts                 # Base64 encode/decode
    UTF8.ts                   # TextEncoder/TextDecoder helpers
    BigIntUtils.ts            # BigInt operations for RSA cryptography
    LangUtils.ts              # General utilities (throttle, etc.)
```

## Integrating with a React shell

```tsx
// Initialize the bridge
const coordinator = new ViewStateCoordinator(wsUrl)

// In a component:
const viewState = ViewScope.use<LoginViewState>("login")
coordinator.submit({ eventCode: "LOGIN", formData: { username, password } })
```

## Security

The bridge implements the server's security protocol:

1. **Handshake**: RSA-OAEP (server public key) to encrypt the AES secret
2. **Sensitive data**: per-session AES-256-GCM, key derived via PBKDF2 (250,000 iterations)
3. **Navigation signature**: a `sign` parameter on every intent, validated by the host
