// Разбор STL ядром W-10 вне основного потока (W-27): файл 50 МиБ разбирается ~3 с и иначе блокировал бы страницу.
import { StlError, parseStl } from './core/index.js';

self.onmessage = ({ data: buffer }) => {
  try {
    const model = parseStl(buffer);
    self.postMessage({ model }, [model.positions.buffer]);
  } catch (error) {
    self.postMessage({ error: error instanceof StlError ? error.code : 'structure' });
  }
};
