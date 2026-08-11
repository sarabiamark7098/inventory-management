"use client";

import { Camera, CameraOff, Keyboard, ScanLine } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";

import { Button, Input } from "@/components/ui/primitives";

const READER_ID = "ict-code-reader";

type Html5QrcodeInstance = import("html5-qrcode").Html5Qrcode;

/**
 * QR / barcode capture (US-2.2).
 *
 * The camera path uses html5-qrcode, loaded lazily so it never runs on the
 * server. Hardware wedge scanners and manual entry work through the text input,
 * which is the fallback whenever camera permission is unavailable — including
 * on plain-HTTP LAN deployments where getUserMedia is blocked.
 */
export function CodeScanner({
  onDetected,
  autoStart = true,
}: {
  onDetected: (code: string) => void;
  autoStart?: boolean;
}) {
  const [cameraOn, setCameraOn] = useState(autoStart);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [manual, setManual] = useState("");

  // Kept in a ref so a new `onDetected` identity never restarts the camera.
  const onDetectedRef = useRef(onDetected);
  useEffect(() => {
    onDetectedRef.current = onDetected;
  });

  useEffect(() => {
    if (!cameraOn) return;

    let disposed = false;
    let scanner: Html5QrcodeInstance | null = null;
    let running = false;

    /**
     * html5-qrcode's `stop()` *throws synchronously* when the scanner was never
     * started — which is exactly what happens when permission is denied. Guard
     * on `running` and swallow, or the throw escapes the effect cleanup and
     * takes the whole page down.
     */
    async function teardown(): Promise<void> {
      const instance = scanner;
      scanner = null;
      if (!instance) return;
      if (running) {
        running = false;
        try {
          await instance.stop();
        } catch {
          /* already stopped */
        }
      }
      try {
        instance.clear();
      } catch {
        /* nothing rendered yet */
      }
    }

    void (async () => {
      try {
        const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import(
          "html5-qrcode"
        );
        if (disposed) return;

        scanner = new Html5Qrcode(READER_ID, {
          verbose: false,
          formatsToSupport: [
            Html5QrcodeSupportedFormats.QR_CODE,
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.CODE_39,
            Html5QrcodeSupportedFormats.EAN_13,
            Html5QrcodeSupportedFormats.DATA_MATRIX,
          ],
        });

        await scanner.start(
          { facingMode: "environment" },
          { fps: 10, qrbox: { width: 240, height: 240 } },
          (decodedText) => onDetectedRef.current(decodedText.trim()),
          () => {
            /* per-frame decode misses are expected; ignore */
          },
        );
        running = true;
        if (disposed) await teardown();
      } catch (err) {
        await teardown();
        if (disposed) return;
        setCameraError(
          err instanceof Error
            ? err.message
            : "Camera unavailable. Use manual entry or a USB scanner.",
        );
        setCameraOn(false);
      }
    })();

    return () => {
      disposed = true;
      void teardown();
    };
  }, [cameraOn]);

  const submitManual = useCallback(() => {
    const code = manual.trim();
    if (!code) return;
    onDetectedRef.current(code);
    setManual("");
  }, [manual]);

  return (
    <div className="space-y-3">
      <div className="overflow-hidden rounded-lg border border-border-subtle bg-black/90">
        <div
          id={READER_ID}
          className={cameraOn ? "min-h-56 [&_video]:w-full" : "hidden"}
        />
        {!cameraOn ? (
          <div className="flex min-h-40 flex-col items-center justify-center gap-2 px-4 py-6 text-center">
            <ScanLine className="size-7 text-white/50" />
            <p className="text-xs text-white/70">
              {cameraError ?? "Camera is off."}
            </p>
            <Button size="sm" onClick={() => {
              setCameraError(null);
              setCameraOn(true);
            }}>
              <Camera className="size-3.5" />
              Start camera
            </Button>
          </div>
        ) : null}
      </div>

      {cameraOn ? (
        <Button size="sm" onClick={() => setCameraOn(false)}>
          <CameraOff className="size-3.5" />
          Stop camera
        </Button>
      ) : null}

      <div className="flex items-end gap-2">
        <label className="flex-1 space-y-1">
          <span className="flex items-center gap-1.5 text-xs font-medium text-ink-muted">
            <Keyboard className="size-3.5" />
            Asset tag, serial, or scanned code
          </span>
          <Input
            value={manual}
            autoFocus
            placeholder="ICT-0001"
            onChange={(e) => setManual(e.target.value)}
            onKeyDown={(e) => {
              // USB scanners emit the payload then Enter.
              if (e.key === "Enter") {
                e.preventDefault();
                submitManual();
              }
            }}
          />
        </label>
        <Button variant="primary" onClick={submitManual} disabled={!manual.trim()}>
          Look up
        </Button>
      </div>
    </div>
  );
}
