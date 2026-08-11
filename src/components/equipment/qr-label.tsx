"use client";

import { Printer } from "lucide-react";
import { useEffect, useState } from "react";

import { Button } from "@/components/ui/primitives";
import type { Equipment } from "@/lib/domain/types";

/** Payload encoded into the QR image; `findEquipmentByCode` understands it. */
export function qrPayload(equipment: Equipment): string {
  return `ICT:${equipment.id}`;
}

/** Renders printable QR labels for one or many assets (US-2.2). */
export function QrLabelSheet({ items }: { items: Equipment[] }) {
  const [images, setImages] = useState<Record<string, string>>({});

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const QRCode = (await import("qrcode")).default;
      const entries = await Promise.all(
        items.map(async (item) => {
          const url = await QRCode.toDataURL(qrPayload(item), {
            margin: 1,
            width: 220,
            errorCorrectionLevel: "M",
          });
          return [item.id, url] as const;
        }),
      );
      if (!cancelled) setImages(Object.fromEntries(entries));
    })();
    return () => {
      cancelled = true;
    };
  }, [items]);

  return (
    <div className="space-y-4">
      <div className="no-print flex justify-end">
        <Button variant="primary" size="sm" onClick={() => window.print()}>
          <Printer className="size-3.5" />
          Print labels
        </Button>
      </div>

      <div
        id="print-region"
        className="grid gap-3 sm:grid-cols-2 md:grid-cols-3"
      >
        {items.map((item) => (
          <figure
            key={item.id}
            className="flex flex-col items-center gap-2 rounded-lg border border-border-subtle bg-white p-3 text-center"
          >
            {images[item.id] ? (
              // eslint-disable-next-line @next/next/no-img-element -- data: URI generated client-side
              <img
                src={images[item.id]}
                alt={`QR code for ${item.assetTag}`}
                className="size-32"
              />
            ) : (
              <div className="size-32 animate-pulse rounded bg-neutral-200" />
            )}
            <figcaption className="space-y-0.5">
              <span className="block font-mono text-sm font-semibold text-neutral-900">
                {item.assetTag}
              </span>
              <span className="block text-[11px] text-neutral-600">
                {item.modelName}
              </span>
              {item.serialNumber ? (
                <span className="block font-mono text-[10px] text-neutral-500">
                  SN {item.serialNumber}
                </span>
              ) : null}
            </figcaption>
          </figure>
        ))}
      </div>
    </div>
  );
}
