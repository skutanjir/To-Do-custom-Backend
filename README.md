# TEST_WUDI Backend Infrastructure: Jarvis v13.0 AI Core and Task Management API

## Executive Summary
This repository contains the enterprise-grade backend infrastructure for the TEST_WUDI ecosystem. It features the Jarvis v13.0 Neural Engine, a rule-based reasoning system with optional GPU acceleration via a Python sidecar. The system is built on Laravel 11 and provides high-concurrency API services for task management, team collaboration, and AI-driven productivity analysis.

---

## Core Technical Stack

| Component | Technology | Implementation Detail |
|-----------|------------|-----------------------|
| Framework | Laravel 11.x | PHP 8.3 with Optimized JIT |
| Database | PostgreSQL | Relational storage with composite indexing |
| Cache/Queue | Redis | High-speed state management and session caching |
| AI Reasoning | PHP Rule Engine | 19-Expert cognitive architecture |
| AI Compute | Python FastAPI | RTX GPU bridging for vector embeddings |
| Security | Laravel Sanctum | Stateful token-based authentication |

---

## AI Architecture: Jarvis v13.0 (Nebula)

The backend implements a unique "Hybrid Intelligence" model that prioritizes local execution over external APIs.

### 1. The Expert Registry
Jarvis decomposes user queries into specialized domain tasks handled by dedicated classes:
- StrategicPlannerExpert: Workload balancing and task sequencing logic.
- HealthVitalityExpert: Mental load monitoring and burnout prevention telemetry.
- SystemIntegrityExpert: Automated data auditing and priority collision detection.
- ResearchExpert: Productivity-focused knowledge synthesis for task decomposition.

### 2. GPU Accelerated Semantic Search
The `gpu_sidecar.py` service bridges the PHP environment to local Nvidia RTX hardware. It utilizes the `all-MiniLM-L6-v2` model to generate high-dimensional vector embeddings, enabling semantic search capabilities that go beyond simple keyword matching.

---

## API Specification and Interaction Map

### Authentication and Identity
| Method | Endpoint | Description | Security |
|--------|----------|-------------|----------|
| POST | /api/register | User account creation | Public |
| POST | /api/login | Token generation | Public |
| GET | /api/user | Current identity profile | Sanctum Auth |

### Task Management (CRUD)
| Method | Endpoint | Description | Constraints |
|--------|----------|-------------|-------------|
| GET | /api/todos | Retrieve task repository | Owner/Team Only |
| POST | /api/todos | Create task record | Validated Input |
| PUT | /api/todos/{id} | Modify existing task | Ownership Check |
| DELETE | /api/todos/{id} | Permanent removal | Ownership Check |

### AI and Intelligence Hub
| Method | Endpoint | Description | Features |
|--------|----------|-------------|----------|
| POST | /api/ai/chat | Main reasoning dispatcher | Multi-intent support |
| GET | /api/ai/experts/insights | Expert telemetry data | Real-time analytics |
| POST | /api/ai/compute-mode | Toggle CPU/GPU processing | Hardware check |

---

## Technical Installation and Setup

### System Prerequisites
- PHP 8.3 or higher
- Composer 2.x
- PostgreSQL 15+
- Redis 7+
- Python 3.10+ (For GPU acceleration)

### Deployment Steps

1. Environment Configuration
Clone the repository and prepare the environment:
```bash
cp .env.example .env
```
Configure your database and hardware settings in the `.env` file:
```text
DB_CONNECTION=pgsql
AI_COMPUTE_DEVICE=gpu # or cpu
GPU_SIDECAR_URL=http://127.0.0.1:8080
```

2. Dependency Acquisition
Install the Laravel framework and its ecosystem:
```bash
composer install
```

3. Database Initialization
Run the optimized migrations including performance indices:
```bash
php artisan migrate
```

4. GPU Sidecar Activation (Optional)
If using an Nvidia RTX card, install Python dependencies and launch the bridge:
```bash
pip install torch sentence-transformers fastapi uvicorn pydantic
python gpu_sidecar.py
```

---

## Engineering Optimization Standards

### Database Performance
The system utilizes composite indices to optimize the most frequent queries:
- `(user_id, is_completed)`: Enables sub-millisecond filtering of active tasks.
- `(team_id)`: Accelerates collaborative workload retrieval.

### AI Efficiency
- Semantic Thesaurus Caching: Expanded keyword mappings are cached to prevent redundant loops.
- Regex Compilation Cache: All intent patterns are pre-compiled and stored in a static registry to reduce CPU overhead per request.

---

## Security Protocols
- IDOR Prevention: Centralized `verifyOwnership` logic ensures data isolation between users and teams.
- Domain Firewall: The Research AI includes an internal classifier to block prompts outside the productivity and task management scope.
- Mass Assignment Protection: Strict `$fillable` and `$request->only` constraints prevent unauthorized attribute modification.

---

## License
Proprietary implementation. Distributed under the MIT License terms.
