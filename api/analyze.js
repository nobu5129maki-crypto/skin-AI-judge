/**
 * Vercel Serverless Function: Gemini API プロキシ
 * APIキーはサーバー側の環境変数 GEMINI_API_KEY に格納（クライアントに露出しない）
 */
export default async function handler(req, res) {
  if (req.method !== "POST") {
    return res.status(405).json({ error: "Method Not Allowed" });
  }

  const apiKey = (
    process.env.GEMINI_API_KEY ||
    process.env.VITE_GEMINI_API_KEY ||
    ""
  ).trim();

  if (!apiKey) {
    return res.status(500).json({
      error: {
        message:
          "APIキーが未設定です。Vercel の Project Settings → Environment Variables で GEMINI_API_KEY を追加し、Redeploy を実行してください。",
        status: "CONFIGURATION_ERROR",
      },
    });
  }

  try {
    const body = typeof req.body === "string" ? JSON.parse(req.body) : req.body;
    const { contents, systemInstruction, generationConfig } = body;

    if (!contents) {
      return res.status(400).json({
        error: { message: "contents is required", status: "INVALID_ARGUMENT" },
      });
    }

    const geminiRes = await fetch(
      `https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite-preview:generateContent?key=${apiKey}`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          contents,
          systemInstruction: systemInstruction || undefined,
          generationConfig: generationConfig || { responseMimeType: "application/json" },
        }),
      }
    );

    let data;
    try {
      data = await geminiRes.json();
    } catch {
      data = { error: { message: await geminiRes.text().catch(() => "API_ERROR") } };
    }

    // Rate limit / quota 系の原因切り分け用に、Gemini が返すヘッダをそのまま返す。
    // これによりフロント側で `retry-after` に応じた待機をできるようにする。
    const geminiHeaders = Object.fromEntries(geminiRes.headers.entries());
    const retryAfter = geminiHeaders["retry-after"];
    const xRateLimitHeaders = Object.fromEntries(
      Object.entries(geminiHeaders).filter(([k]) => k.toLowerCase().startsWith("x-ratelimit-"))
    );

    if (!geminiRes.ok) {
      if (retryAfter) {
        res.setHeader("retry-after", retryAfter);
      }

      // 既存のレスポンス形を壊さないため、error 部分だけ補足する。
      const originalError =
        data && typeof data === "object" && data.error && typeof data.error === "object"
          ? data.error
          : { message: data?.error?.message || data?.message || "API_ERROR" };

      return res.status(geminiRes.status).json({
        ...data,
        error: {
          ...originalError,
          rateLimit: {
            retryAfter,
            headers: xRateLimitHeaders,
          },
        },
      });
    }

    return res.status(200).json(data);
  } catch (e) {
    console.error("[api/analyze]", e);
    return res.status(500).json({
      error: {
        message: e.message || "Internal Server Error",
        status: "INTERNAL",
      },
    });
  }
}
