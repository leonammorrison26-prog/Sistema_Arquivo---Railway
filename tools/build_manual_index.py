import json
import re
from pathlib import Path

import fitz


BASE_DIR = Path(__file__).resolve().parents[1]
MANUAIS_DIR = BASE_DIR / "manuais"
OUTPUT = MANUAIS_DIR / "manual_index.json"


def normalize(text: str) -> str:
    text = re.sub(r"\s+", " ", text.replace("\x00", " ")).strip()
    return text


def chunks(text: str, size: int = 1300, overlap: int = 180):
    if len(text) <= size:
        if text:
            yield text
        return

    start = 0
    while start < len(text):
        end = min(len(text), start + size)
        chunk = text[start:end].strip()
        if chunk:
            yield chunk
        if end >= len(text):
            break
        start = max(0, end - overlap)


def main():
    records = []
    for pdf in sorted(MANUAIS_DIR.glob("*.pdf")):
        with fitz.open(pdf) as doc:
            for page_index, page in enumerate(doc, start=1):
                text = normalize(page.get_text("text"))
                for chunk_index, chunk in enumerate(chunks(text), start=1):
                    records.append(
                        {
                            "file": pdf.name,
                            "page": page_index,
                            "chunk": chunk_index,
                            "text": chunk,
                        }
                    )

    OUTPUT.write_text(json.dumps(records, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"indexed={len(records)} output={OUTPUT}")


if __name__ == "__main__":
    main()
