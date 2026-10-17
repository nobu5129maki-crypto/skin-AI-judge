/**
 * Vercel Serverless Function: Gemini API プロキシ
 * APIキーはサーバー側の環境変数 GEMINI_API_KEY に格納（クライアントに露出しない）
 *
 * 先頭は最新の Flash。混雑・未提供・思考設定の非対応時だけ次のモデルへ落とす。
 */
const MODEL_CANDIDATES = [
  "gemini-3.8-flash",
  "gemini-3.6-flash",
  "gemini-3.5-flash-lite",
];

function buildPayload(body, model, useThinking) {
  const clientConfig =
    body.generationConfig && typeof body.generationConfig === "object"
      ? body.generationConfig
      : {};
  const generationConfig = {
    ...clientConfig,
    responseMimeType: "application/json",
    maxOutputTokens: Math.max(Number(clientConfig.maxOutputTokens) || 0, 4096),
  };
  if (useThinking) {
    generationConfig.thinkingConfig = { thinkingLevel: "low" };
  } else {
    delete generationConfig.thinkingConfig;
  }
  return JSON.stringify({
    contents: body.contents,
    systemInstruction: body.systemInstruction || undefined,
    generationConfig,
  });
}

function isThinkingConfigError(status, data) {
  if (status !== 400) return false;
  const message = String(data?.error?.message || data?.message || "");
  return /thinking/i.test(message) && !/image/i.test(message);
}

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
    const { contents } = body || {};

    if (!contents) {
      return res.status(400).json({
        error: { message: "contents is required", status: "INVALID_ARGUMENT" },
      });
    }

    let geminiRes;
    let data;
    let modelUsed = MODEL_CANDIDATES[0];

    for (let i = 0; i < MODEL_CANDIDATES.length; i++) {
      const model = MODEL_CANDIDATES[i];
      modelUsed = model;
      const attempts = [true, false];
      for (let attempt = 0; attempt < attempts.length; attempt++) {
        const useThinking = attempts[attempt];
        geminiRes = await fetch(
          `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${apiKey}`,
          {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: buildPayload(body, model, useThinking),
          }
        );
        try {
          data = await geminiRes.json();
        } catch {
          data = { error: { message: await geminiRes.text().catch(() => "API_ERROR") } };
        }
        if (geminiRes.ok) break;
        if (useThinking && isThinkingConfigError(geminiRes.status, data)) {
          console.warn(`[api/analyze] model=${model} thinking非対応 -> 思考なしで再試行`);
          continue;
        }
        break;
      }

      const retryable = [429, 404, 503].includes(geminiRes.status);
      if (geminiRes.ok || !retryable || i === MODEL_CANDIDATES.length - 1) break;
      console.warn(`[api/analyze] model=${model} status=${geminiRes.status} -> 次のモデルで再試行`);
    }

    const geminiHeaders = Object.fromEntries(geminiRes.headers.entries());
    const retryAfter = geminiHeaders["retry-after"];
    const xRateLimitHeaders = Object.fromEntries(
      Object.entries(geminiHeaders).filter(([k]) => k.toLowerCase().startsWith("x-ratelimit-"))
    );

    if (!geminiRes.ok) {
      if (retryAfter) {
        res.setHeader("retry-after", retryAfter);
      }

      const originalError =
        data && typeof data === "object" && data.error && typeof data.error === "object"
          ? data.error
          : { message: data?.error?.message || data?.message || "API_ERROR" };

      return res.status(geminiRes.status).json({
        ...data,
        error: {
          ...originalError,
          model: modelUsed,
          rateLimit: {
            retryAfter,
            headers: xRateLimitHeaders,
          },
        },
      });
    }

    res.setHeader("x-skin-model", modelUsed);
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
