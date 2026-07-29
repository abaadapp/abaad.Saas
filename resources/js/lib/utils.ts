import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** دمج أصناف Tailwind مع حلّ التعارضات — الأساس الذي تبني عليه مكوّنات shadcn */
export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
