export function slotColorForIndex(idx: number): string {
  switch (idx) {
    case 0:
      return 'sky'
    case 1:
      return 'rose'
    case 2:
      return 'amber'
    case 3:
      return 'emerald'
    case 4:
      return 'fuchsia'
    case 5:
      return 'indigo'
    case 6:
      return 'teal'
    default:
      return 'violet'
  }
}

export function ringForColor(color: string): string {
  if (color === 'sky') return 'ring-2 ring-sky-500 ring-offset-1 ring-offset-background shadow-sm'
  if (color === 'rose') return 'ring-2 ring-rose-500 ring-offset-1 ring-offset-background shadow-sm'
  if (color === 'amber') return 'ring-2 ring-amber-500 ring-offset-1 ring-offset-background shadow-sm'
  if (color === 'emerald') return 'ring-2 ring-emerald-500 ring-offset-1 ring-offset-background shadow-sm'
  if (color === 'fuchsia') return 'ring-2 ring-fuchsia-500 ring-offset-1 ring-offset-background shadow-sm'
  if (color === 'indigo') return 'ring-2 ring-indigo-500 ring-offset-1 ring-offset-background shadow-sm'
  if (color === 'teal') return 'ring-2 ring-teal-500 ring-offset-1 ring-offset-background shadow-sm'
  if (color === 'cyan') return 'ring-2 ring-cyan-500 ring-offset-1 ring-offset-background shadow-sm'
  if (color === 'violet') return 'ring-2 ring-violet-500 ring-offset-1 ring-offset-background shadow-sm'
  return 'ring-2 ring-violet-500 ring-offset-1 ring-offset-background shadow-sm'
}

export function ringForSlot(idx: number): string {
  return ringForColor(slotColorForIndex(idx))
}

export function badgeBgForColor(color: string): string {
  if (color === 'sky') return 'bg-sky-500/90'
  if (color === 'rose') return 'bg-rose-500/90'
  if (color === 'amber') return 'bg-amber-500/90'
  if (color === 'emerald') return 'bg-emerald-500/90'
  if (color === 'fuchsia') return 'bg-fuchsia-500/90'
  if (color === 'indigo') return 'bg-indigo-500/90'
  if (color === 'teal') return 'bg-teal-500/90'
  if (color === 'cyan') return 'bg-cyan-500/90'
  if (color === 'violet') return 'bg-violet-500/90'
  return 'bg-violet-500/90'
}

export function badgeClassForSlot(idx: number): string {
  return badgeBgForColor(slotColorForIndex(idx))
}
