// Повторный заказ и «пересчитать» (W-15, §1.2): калькулятор открыт с ?models=<id,…> — файлы скачиваются защищённой
// выдачей /stl/<id> (права проверяет сервер, W-32) в ArrayBuffer и проходят тот же разбор ядра, что и загруженные.
// Чужой или неизвестный id сервер отдаёт 404 — такой файл пропускается без сообщения (существование не раскрывается).

/** Id моделей из query: только числа, без повторов, не больше max. */
export function modelIds(search, max) {
  const value = new URLSearchParams(search).get('models') || '';
  return [...new Set(value.split(',').map((id) => id.trim()).filter((id) => /^\d+$/.test(id)))].slice(0, max);
}

/** Имя файла из Content-Disposition (filename* в UTF-8, иначе filename). */
export function fileName(disposition, fallback) {
  const encoded = /filename\*=utf-8''([^;]+)/i.exec(disposition || '');
  if (encoded) {
    try {
      return decodeURIComponent(encoded[1]);
    } catch {
      // неверная кодировка — ниже простое имя
    }
  }
  const plain = /filename="?([^";]+)"?/i.exec(disposition || '');
  return plain ? plain[1] : fallback;
}

/** Скачивает модели по id и передаёт их add(name, buffer) по порядку; ответы не 200 пропускаются. */
export async function loadSavedModels(ids, add, fetchFile = (id) => fetch(`/stl/${id}`, { credentials: 'same-origin' })) {
  const files = await Promise.all(ids.map(async (id) => {
    try {
      const response = await fetchFile(id);
      if (!response.ok) return null;
      return { name: fileName(response.headers.get('Content-Disposition'), `model-${id}.stl`), buffer: await response.arrayBuffer() };
    } catch {
      return null;
    }
  }));
  const results = [];
  for (const file of files.filter(Boolean)) results.push(await add(file.name, file.buffer));
  return results;
}
