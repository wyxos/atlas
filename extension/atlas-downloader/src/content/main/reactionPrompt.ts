export type SheetReactionPrompt =
  | {
      kind: 're-download';
      title: string;
      message: string;
      confirmLabel: string;
      cancelLabel: string;
      alternateLabel: string;
    }
  | {
      kind: 'clear-download';
      title: string;
      message: string;
      confirmLabel: string;
      cancelLabel: string;
      alternateLabel: string;
      danger: true;
    };

export function resolveSheetReactionPrompt(
  reactionType: string,
  downloaded: boolean,
  blacklist: boolean
): SheetReactionPrompt | null {
  if (!downloaded) {
    return null;
  }

  if (reactionType !== 'dislike') {
    return {
      kind: 're-download',
      title: 'Already downloaded',
      message: 'This file is already downloaded. Re-download before updating the reaction?',
      confirmLabel: 'Re-download',
      cancelLabel: 'Keep existing file',
      alternateLabel: 'Cancel',
    };
  }

  return {
    kind: 'clear-download',
    title: blacklist ? 'Blacklist file' : 'Dislike file',
    message: 'Delete the downloaded file before applying this action?',
    confirmLabel: 'Delete then proceed',
    cancelLabel: 'Keep file and proceed',
    alternateLabel: 'Cancel',
    danger: true,
  };
}
