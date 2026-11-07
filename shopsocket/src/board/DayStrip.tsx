/*
 * The board's "This day" strip: sales, carts left behind, recovered orders and checkout conversion over
 * the last 24 hours, each against its daily average over the last seven days and drawn day by day
 * over them.
 */
import { __, _n, sprintf } from "@wordpress/i18n";
import { Link, Stack, Text } from "@wordpress/ui";
import { money } from "./format";
import { Section, Stat } from "./ui";

type Config = Pick<ShopSocketBoardConfig, "journeysUrl">;

/** A difference to one decimal, without a trailing zero. */
const plain = (d: number) => String(Math.round(d * 10) / 10);

/** "+3 vs the 7-day average", "same as the 7-day average"; `show` words the size of the difference. */
function delta(
  now: number,
  average: number,
  show: (size: number) => string = plain,
): string {
  const d = now - average;
  if (Math.abs(d) < 0.05)
    return __("same as the 7-day average", "shopsocket");
  /* translators: %s: a signed difference, such as +3 or -2 */
  return sprintf(
    __("%s vs the 7-day average", "shopsocket"),
    `${d > 0 ? "+" : "−"}${show(Math.abs(d))}`,
  );
}

/**
 * The "This day" card, or one line pointing at WordSocket's Journeys tab while its History is off.
 *
 * @since 0.8.0
 */
export function DayStrip({
  strip,
  config,
}: {
  strip: WooDayStrip | null | undefined;
  config: Config;
}) {
  const historyOff = (
    <Text variant="body-sm" className="shopsocket-muted" render={<p />}>
      {__(
        "Turn on History in WordSocket to see how the last 24 hours compare with the last seven days.",
        "shopsocket",
      )}{" "}
      <Link href={config.journeysUrl}>{__("Open Journeys", "shopsocket")}</Link>
    </Text>
  );
  if (!strip) {
    return (
      <Section title={__("This day", "shopsocket")}>{historyOff}</Section>
    );
  }
  const { sales, left, recovered, conversion } = strip;
  /* translators: %d: carts in one day */
  const carts = (v: number) =>
    sprintf(_n("%d cart", "%d carts", v, "shopsocket"), v);
  /* translators: %d: orders in one day */
  const orders = (v: number) =>
    sprintf(_n("%d order", "%d orders", v, "shopsocket"), v);
  return (
    <Section
      title={__("This day", "shopsocket")}
      description={__(
        "Sales, carts, recovered sales and checkouts over the last 24 hours, each against its daily average over the last seven days.",
        "shopsocket",
      )}
    >
      <Stack direction="column" gap="lg">
        <div className="shopsocket-stats">
          <Stat
            name="sales"
            value={money(sales.value, sales.currency)}
            label={__("Sales", "shopsocket")}
            compare={{
              now: sales.value,
              before: sales.average,
              goodWhen: "up",
            }}
            days={sales.days}
            dates={strip.trend_days}
            dayLabel={(v) => money(v, sales.currency)}
            detail={sprintf(
              /* translators: 1: the paid orders placed, 2: the change in what they came to against the 7-day average */
              __("%1$s, %2$s", "shopsocket"),
              orders(sales.count),
              delta(sales.value, sales.average, (size) =>
                money(size, sales.currency),
              ),
            )}
          />
          <Stat
            name="left"
            value={String(left.count)}
            label={__("Carts left behind", "shopsocket")}
            compare={{
              now: left.count,
              before: left.average,
              goodWhen: "down",
            }}
            days={left.days}
            dates={strip.trend_days}
            dayLabel={carts}
            detail={sprintf(
              /* translators: 1: the change against the 7-day average, 2: the value of those carts */
              __("%1$s, %2$s in them", "shopsocket"),
              delta(left.count, left.average),
              money(left.value, left.currency),
            )}
          />
          <Stat
            name="recovered"
            value={String(recovered.count)}
            label={__("Recovered orders", "shopsocket")}
            compare={{
              now: recovered.count,
              before: recovered.average,
              goodWhen: "up",
            }}
            days={recovered.days}
            dates={strip.trend_days}
            dayLabel={orders}
            detail={sprintf(
              /* translators: 1: the value of the recovered orders, 2: the change against the 7-day average */
              __("%1$s in orders after a moment, %2$s", "shopsocket"),
              money(recovered.value, left.currency),
              delta(recovered.count, recovered.average),
            )}
          />
          <Stat
            name="conversion"
            value={`${conversion.percent}%`}
            label={__("Checkout conversion", "shopsocket")}
            compare={{
              now: conversion.percent,
              before: conversion.average_percent,
              goodWhen: "up",
            }}
            days={conversion.days}
            dates={strip.trend_days}
            dayLabel={(v) => `${v}%`}
            detail={sprintf(
              /* translators: 1: orders, 2: shoppers who reached checkout, 3: the change against the 7-day figure in points */
              __("%1$d of %2$d who reached checkout, %3$s", "shopsocket"),
              conversion.orders,
              conversion.reached,
              delta(
                conversion.percent,
                conversion.average_percent,
                (size) =>
                  /* translators: %d: percentage points */
                  sprintf(__("%d pts", "shopsocket"), Math.round(size)),
              ),
            )}
          />
        </div>
        {strip.journeys === false && historyOff}
      </Stack>
    </Section>
  );
}
