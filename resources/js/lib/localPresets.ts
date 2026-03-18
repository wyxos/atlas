import { LOCAL_TAB_FILTER_PRESETS } from '@/utils/tabFilter';

export const LOCAL_PRESET_LABEL_BY_VALUE: Record<string, string> = Object.fromEntries(
  LOCAL_TAB_FILTER_PRESETS.map((preset) => [preset.value, preset.label])
);

export function getLocalPresetLabel(value: unknown): string | null {
  if (typeof value !== "string" || value.trim() === "") {
    return null
  }

  return LOCAL_PRESET_LABEL_BY_VALUE[value] ?? value
}
