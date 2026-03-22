# TEST_WUDI Backend Infrastructure: Enterprise AI Core and Task Management API

## Executive Summary
This repository contains the core backend infrastructure for the TEST_WUDI productivity ecosystem. The system is architected around the Jarvis v13.0 Neural Engine, a sophisticated hybrid reasoning system that combines pure PHP logic with local GPU-accelerated semantic processing. Built on Laravel 11, the infrastructure provides high-concurrency API services, advanced task lifecycle management, and a robust security framework for enterprise-grade productivity.

---

## Technical Architecture Overview

### 1. The Neural Reasoning Pipeline (Jarvis v13.0)
The backend implements a multi-stage NLP pipeline that processes natural language without external LLM dependencies:
- Normalization Layer: Sanitizes input, resolves common abbreviations, and performs multi-dialect language detection (Indonesian, English, and regional dialects).
- Contextual Resolution: Resolves anaphora and pronouns based on previous conversation turns and user personality data.
- Semantic Weighted Scoring: An algorithm that ranks intent candidates based on keyword density, word boundaries, and historical context.
- Expert Delegation: Dispatches queries to 19 specialized "Cognitive Experts" for domain-specific logic.
- GPU Synthesis: High-dimensional vector embeddings are generated via a local Python sidecar for meaning-based task searching.

### 2. The Expert Registry
The system utilizes a modular expert architecture. Key components include:
- StrategicPlannerExpert: Optimizes task sequencing using Eisenhower matrix principles.
- MentalLoadExpert: Monitors user productivity density to provide burnout prevention insights.
- ResearchExpert: A v13.0 addition that decomposes high-level goals into actionable task lists.
- SystemIntegrityExpert: Performs real-time audits of task priorities and deadline consistency.
- HabitEvolutionExpert: Analyzes recurring task patterns to suggest automated habit tracking.

---

## GPU Acceleration (Nebula Architecture)

The backend utilizes the Nebula Architecture to leverage local Nvidia RTX hardware for high-performance neural processing.

### Python Sidecar (gpu_sidecar.py)
The sidecar is a FastAPI service that bridges the PHP environment to the GPU:
- Model: all-MiniLM-L6-v2 (Transformer-based embeddings).
- Hardware: Optimized for CUDA 13.0 and Nvidia RTX 3050+ hardware.
- Functionality: Provides vector similarity searching and semantic intent mapping.

---

## Core Technology Stack

| Component | Technology | implementation Detail |
|-----------|------------|-----------------------|
| Framework | Laravel 11.x | PHP 8.3 with JIT optimization |
| Database | PostgreSQL 15 | Relational storage with composite indexing |
| AI Compute | Python / FastAPI | PyTorch + CUDA 13.0 |
| Cache Layer | Redis 7 | High-speed intent and regex caching |
| Auth System | Laravel Sanctum | Stateful session and token management |
| Real-time | SSE (Server-Sent Events) | Streaming AI responses for low-latency feedback |

---

## API Interaction Specification

### Authentication
| Method | Endpoint | Description | Security |
|--------|----------|-------------|----------|
| POST | /api/register | Account creation and device migration | Public |
| POST | /api/login | Bearer token generation | Public |
| POST | /api/logout | Session termination | Authenticated |

### AI and Intelligence Hub
| Method | Endpoint | Description | Features |
|--------|----------|-------------|----------|
| POST | /api/ai/chat | Main intelligence dispatcher | Multi-intent parsing |
| POST | /api/ai/stream | SSE-based response streaming | Real-time chunks |
| GET | /api/ai/experts/insights | Expert telemetry and reports | JSON analytics |
| POST | /api/ai/voice-preference | TTS and Voice behavior settings | Personality sync |

### Task Repository (CRUD)
| Method | Endpoint | Description | Auth Level |
|--------|----------|-------------|------------|
| GET | /api/todos | Fetch task list with team merge | Device/User |
| POST | /api/todos | Persistent task creation | Validated |
| PUT | /api/todos/{id} | Update task metadata | Ownership verified |
| DELETE | /api/todos/{id} | Soft/Hard task removal | Ownership verified |

---

## Installation and Environment Configuration

### Prerequisites
- PHP 8.3 or higher
- Composer 2.x
- PostgreSQL 15+
- Redis 7+
- Python 3.10+ (Required for GPU mode)
- Nvidia Driver 530+ with CUDA support

### Backend Setup
1. Clone the repository and install dependencies:
```bash
git clone https://github.com/skutanjir/To-Do-custom-Backend.git
cd To-Do-custom-Backend
composer install
```
2. Configure environment variables:
Copy .env.example to .env and configure database credentials and the GPU endpoint:
```text
DB_CONNECTION=pgsql
AI_COMPUTE_DEVICE=gpu
GPU_SIDECAR_URL=http://127.0.0.1:8080
```
3. Initialize the database:
```bash
php artisan migrate
```

### GPU Sidecar Activation
1. Install Python neural processing requirements:
```bash
pip install torch sentence-transformers fastapi uvicorn pydantic
```
2. Launch the sidecar:
```bash
python gpu_sidecar.py
```

---

## Performance and Security Standards

### Database Optimization
The system is optimized for high-volume task data through:
- Indexing: Composite indices on (user_id, is_completed) and (team_id).
- Eager Loading: Standardized use of with() and withCount() to prevent N+1 query overhead.

### Security Implementation
- IDOR Prevention: A centralized verification layer ensures that task access is strictly bound to the authenticated user or their authorized team members.
- Domain Guard: The AI engine features a "Firewall" expert that rejects prompts attempting to access data outside the task management context.

---

## License and Terms
This backend software is proprietary. Distribution or modification requires explicit authorization under the MIT License terms.
