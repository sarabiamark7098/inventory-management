import { NextResponse } from "next/server";

import { handlePull } from "@/lib/server/sync-service";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

/**
 * Downloads everything changed since `last_synced_at` (omit for a full
 * bootstrap). Soft-deleted rows are included so clients learn about removals.
 */
export async function GET(request: Request) {
  const param = new URL(request.url).searchParams.get("last_synced_at");

  let since: Date | null = null;
  if (param) {
    const parsed = new Date(param);
    if (Number.isNaN(parsed.getTime())) {
      return NextResponse.json(
        { error: "`last_synced_at` must be an ISO-8601 timestamp." },
        { status: 400 },
      );
    }
    since = parsed;
  }

  try {
    const payload = await handlePull(since);
    return NextResponse.json(payload);
  } catch (err) {
    console.error("[sync/pull]", err);
    return NextResponse.json(
      { error: "Sync pull failed. The client will retry." },
      { status: 500 },
    );
  }
}
