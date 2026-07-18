/**
 * The docker --platform flag matching the host, so multi-arch images
 * (php:8.3-cli, composer:2) run natively. Without an explicit platform,
 * docker reuses whatever variant the local tag cache holds — a stale
 * amd64 pull on Apple Silicon silently runs every container under QEMU
 * emulation (and prints a platform-mismatch warning on each run).
 */
export function dockerPlatform() {
  return process.arch === "arm64" ? "linux/arm64" : "linux/amd64";
}
