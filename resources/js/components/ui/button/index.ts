import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-smart-blue-500 text-white hover:bg-smart-blue-600 focus-visible:border-smart-blue-400 focus-visible:ring-smart-blue-400/50",
        destructive:
          "bg-danger-500 text-white hover:bg-danger-600 focus-visible:border-danger-400 focus-visible:ring-danger-400/50",
        outline: "",
        secondary:
          "bg-sapphire-500 text-white hover:bg-sapphire-600 focus-visible:border-sapphire-400 focus-visible:ring-sapphire-400/50",
        ghost: "",
        link: "text-smart-blue-400 underline-offset-4 hover:underline hover:text-smart-blue-300",
      },
      color: {
        default:
          "",
        danger:
          "",
      },
      size: {
        "default": "h-9 px-4 py-2 has-[>svg]:px-3",
        "sm": "h-8 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
        "lg": "h-10 rounded-md px-6 has-[>svg]:px-4",
        "icon": "size-9",
        "icon-sm": "size-8",
        "icon-lg": "size-10",
      },
    },
    compoundVariants: [
      {
        variant: "outline",
        color: "default",
        class: "border border-smart-blue-500 bg-transparent text-smart-blue-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 focus-visible:ring-smart-blue-400/50",
      },
      {
        variant: "outline",
        color: "danger",
        class: "border border-danger-500 bg-transparent text-danger-100 hover:bg-danger-700 hover:border-danger-400 hover:text-danger-100 focus-visible:ring-danger-400/50",
      },
      {
        variant: "ghost",
        color: "default",
        class: "border border-twilight-indigo-500 bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 focus-visible:ring-smart-blue-400/50",
      },
      {
        variant: "ghost",
        color: "default",
        class: "bg-smart-blue-500 text-white border-2 border-smart-blue-400 hover:bg-smart-blue-400",
      },
      {
        variant: "ghost",
        color: "danger",
        class: "bg-danger-500 text-white border-2 border-danger-400 hover:bg-danger-600",
      },
    ],
    defaultVariants: {
      variant: "default",
      color: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
