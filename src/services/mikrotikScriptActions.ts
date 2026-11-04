export type ClipboardWriter = Pick<Clipboard, "writeText">;

export type ScriptDownloadPlatform = {
  createObjectUrl: (blob: Blob) => string;
  clickDownload: (url: string, filename: string) => void;
  revokeObjectUrl: (url: string) => void;
  defer: (callback: () => void) => void;
};

export async function copyRouterOsScript(script: string, clipboard?: ClipboardWriter): Promise<void> {
  const writer = clipboard ?? (typeof navigator !== "undefined" ? navigator.clipboard : undefined);
  if (!writer) throw new Error("Clipboard API is unavailable in this browser context.");
  await writer.writeText(script);
}

function browserDownloadPlatform(): ScriptDownloadPlatform {
  return {
    createObjectUrl: (blob) => URL.createObjectURL(blob),
    clickDownload: (url, filename) => {
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = filename;
      anchor.click();
    },
    revokeObjectUrl: (url) => URL.revokeObjectURL(url),
    defer: (callback) => { window.setTimeout(callback, 1000); },
  };
}

export function downloadRouterOsScript(
  script: string,
  filename: string,
  platform: ScriptDownloadPlatform = browserDownloadPlatform(),
): void {
  const blob = new Blob([script], { type: "text/plain;charset=utf-8" });
  const objectUrl = platform.createObjectUrl(blob);
  try {
    platform.clickDownload(objectUrl, filename);
  } catch (error) {
    platform.revokeObjectUrl(objectUrl);
    throw error;
  }
  platform.defer(() => platform.revokeObjectUrl(objectUrl));
}
