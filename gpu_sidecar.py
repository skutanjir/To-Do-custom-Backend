import os
import sys
import json
import torch
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import List, Optional
from sentence_transformers import SentenceTransformer, util

# v13.0 GPU Sidecar - Nvidia RTX 3050 Optimized
app = FastAPI(title="Jarvis GPU Sidecar")

# Check CUDA
DEVICE = "cuda" if torch.cuda.is_available() else "cpu"
print(f"--- Jarvis v13.0 Booting on {DEVICE} ---")

# Load lightweight but powerful model for embeddings
# 'all-MiniLM-L6-v2' is extremely fast and perfect for tasks
model = SentenceTransformer("all-MiniLM-L6-v2", device=DEVICE)


class SearchRequest(BaseModel):
    query: str
    tasks: List[str]
    threshold: Optional[float] = 0.3


@app.get("/status")
async def get_status():
    return {
        "version": "13.0.0",
        "device": DEVICE,
        "gpu_name": torch.cuda.get_device_name(0) if DEVICE == "cuda" else "N/A",
        "memory_allocated": f"{torch.cuda.memory_allocated(0) / 1024**2:.2f} MB"
        if DEVICE == "cuda"
        else "0 MB",
    }


@app.post("/semantic-search")
async def semantic_search(req: SearchRequest):
    if not req.tasks:
        return {"matches": []}

    # Domain Firewall: Ensure query is todo-related
    # (Simple check, advanced check in PHP)

    # Compute embeddings
    query_emb = model.encode(req.query, convert_to_tensor=True)
    task_embs = model.encode(req.tasks, convert_to_tensor=True)

    # Compute cosine similarity
    cos_scores = util.cos_sim(query_emb, task_embs)[0]

    # Filter by threshold
    results = []
    for idx, score in enumerate(cos_scores):
        if score > req.threshold:
            results.append({"index": idx, "score": float(score)})

    # Sort by score
    results = sorted(results, key=lambda x: x["score"], reverse=True)

    return {"matches": results}


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=8080)
