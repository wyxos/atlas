export interface CopyToClipboardOptions {
    showToast?: boolean;
}

export async function copyToClipboard(
    text: string,
    _label: string,
    _options?: CopyToClipboardOptions
): Promise<void> {
    await navigator.clipboard.writeText(text);
}
