export function coerceBoolean(value: unknown): boolean {
    if (value === true) {
        return true;
    }

    if (value === false || value === null || value === undefined) {
        return false;
    }

    if (typeof value === 'number') {
        return value !== 0;
    }

    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase();

        if (normalized === '' || normalized === '0' || normalized === 'false' || normalized === 'off' || normalized === 'no') {
            return false;
        }

        if (normalized === '1' || normalized === 'true' || normalized === 'on' || normalized === 'yes') {
            return true;
        }
    }

    return Boolean(value);
}
