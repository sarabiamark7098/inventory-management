import { NextResponse } from "next/server";

import {
  SyncRequestError,
  handlePush,
  parsePushRequest,
} from "@/lib/server/sync-service";

export const dynamic = "force-dynamic";
export const runtime = "nodejs";

/** Uploads queued offline mutations from a client's outbox. */
export async function POST(request: Request) {
  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json(
      { error: "Request body must be valid JSON." },
      { status: 400 },
    );
  }

  try {
    const parsed = parsePushRequest(body);
    const response = await handlePush(parsed);
    return NextResponse.json(response);
  } catch (err) {
    if (err instanceof SyncRequestError) {
      return NextResponse.json({ error: err.message }, { status: 400 });
    }
    console.error("[sync/push]", err);
    return NextResponse.json(
      { error: "Sync push failed. The client will retry." },
      { status: 500 },
    );
  }
}
