const MAILPIT_URL = process.env.MAILPIT_URL ?? "http://localhost:8025";

interface MessageSummary {
  ID: string;
}

/**
 * Menunggu email undangan untuk alamat tertentu di Mailpit lalu mengembalikan token dari tautannya.
 * Token mentah memang hanya ada di email (tidak di respons API maupun basis data).
 */
export async function invitationTokenFor(email: string, timeoutMs = 30_000): Promise<string> {
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    const search = await fetch(`${MAILPIT_URL}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`);
    if (search.ok) {
      const { messages } = (await search.json()) as { messages: MessageSummary[] };
      const first = messages[0];
      if (first) {
        const message = await fetch(`${MAILPIT_URL}/api/v1/message/${first.ID}`);
        const { Text } = (await message.json()) as { Text: string };
        const match = /\/invitations\/([A-Za-z0-9_-]{43})/.exec(Text);
        if (match?.[1]) return match[1];
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }

  throw new Error(`Email undangan untuk ${email} tidak muncul di Mailpit dalam ${timeoutMs / 1000} detik.`);
}
