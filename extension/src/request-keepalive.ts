const MAX_KEEPALIVE_REQUEST_BYTES = 60 * 1024;

export function shouldUseKeepaliveRequest(bodyJson: string): boolean {
    return new TextEncoder().encode(bodyJson).byteLength <= MAX_KEEPALIVE_REQUEST_BYTES;
}
