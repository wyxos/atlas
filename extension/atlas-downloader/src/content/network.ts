export function stripHash(value: string): string {
  const hashPos = value.indexOf('#');
  if (hashPos === -1) {
    return value;
  }
  return value.slice(0, hashPos);
}
