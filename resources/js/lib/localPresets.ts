export const LOCAL_PRESET_LABEL_BY_VALUE: Record<string, string> = {
  reacted_random: "Reacted (Random)",
  inbox_fresh: "Inbox (Fresh)",
  inbox_newest: "Inbox (Newest)",
  inbox_oldest: "Inbox (Oldest)",
  disliked_any: "Disliked (Any)",
  disliked_manual: "Disliked (Manual)",
  disliked_auto: "Disliked (Auto)",
  blacklisted_any: "Blacklisted (Any)",
  blacklisted_manual: "Blacklisted (Manual)",
  blacklisted_auto: "Blacklisted (Auto)",
}

export function getLocalPresetLabel(value: unknown): string | null {
  if (typeof value !== "string" || value.trim() === "") {
    return null
  }

  return LOCAL_PRESET_LABEL_BY_VALUE[value] ?? value
}
