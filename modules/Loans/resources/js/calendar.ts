import type { LoanSummary } from "./http";

/**
 * Helpers of the calendar views: days without time, colours of the bars by status.
 */

export const DAY = 24 * 60 * 60 * 1000;

/**
 * Start of the day of a date.
 */
export const startOfDay = (date: Date) =>
  new Date(date.getFullYear(), date.getMonth(), date.getDate());

/**
 * Monday of the week of a date.
 */
export const startOfWeek = (date: Date) => {
  const day = startOfDay(date);
  return addDays(day, -((day.getDay() + 6) % 7));
};

export const addDays = (date: Date, days: number) =>
  new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);

/**
 * Date as "2026-11-12" in local time (for urls and inputs).
 */
export const isoDate = (date: Date) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;

/**
 * End of a loan in the calendar: an overdue loan runs until today.
 */
export const endOf = (loan: LoanSummary) => {
  const end = new Date(loan.return_at);
  return loan.overdue_days
    ? new Date(Math.max(end.getTime(), Date.now()))
    : end;
};

/**
 * Classes and style of a bar: colour by status, drafts hatched, overdue red.
 */
export const barStyle = (loan: LoanSummary) => {
  if (loan.overdue_days) {
    return { class: "bg-red-600 text-white", style: {} };
  }
  if (loan.status === "draft") {
    return {
      class: "text-ink ring-1 ring-inset ring-line",
      style: {
        background:
          "repeating-linear-gradient(135deg, rgb(var(--color-surface-muted)) 0 6px, rgb(var(--color-surface)) 6px 12px)",
      },
    };
  }
  const classes: Record<string, string> = {
    confirmed: "bg-accent text-white",
    issued: "bg-yellow-500 text-black",
    unchecked: "bg-orange-400 text-black",
    defects: "bg-red-200 text-red-900 dark:bg-red-900 dark:text-red-100",
    closed: "bg-surface-muted text-ink-muted ring-1 ring-inset ring-line",
  };
  return { class: classes[loan.status] ?? classes.closed, style: {} };
};
