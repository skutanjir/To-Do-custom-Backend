# TEST_WUDI Backend Infrastructure

## Executive Summary
This repository contains the core backend infrastructure for the TEST_WUDI productivity ecosystem. The system relies on the Jarvis v13.0 Neural Engine, a hybrid reasoning system that combines PHP logic with local GPU-accelerated semantic processing. Built on Laravel 11, the infrastructure provides high-concurrency API services, advanced task lifecycle management, and a strict security framework designed for enterprise-grade productivity.

## System Architecture

The architecture separates standard HTTP request handling from intensive machine learning operations. It relies on two primary components: the Laravel core application and the Python FastAPI sidecar. The current design doesn't require external machine learning dependencies for its core natural language pipeline.

### Core Infrastructure (Laravel 11)
The primary backend handles client requests, authentication, database transactions, and business logic routing. It uses PHP 8.3 with JIT optimization to ensure low-latency responses for standard CRUD operations. Redis 7 serves as the caching layer for high-speed intent resolution and session state management.

### Cognitive Processing Engine (Jarvis v13.0)
The backend implements a multi-stage natural language processing pipeline that processes text natively.
* Normalization Layer: Sanitizes input, resolves common abbreviations, and performs multi-dialect language detection including Indonesian, English, and regional dialects.
* Contextual Resolution: Resolves anaphora and pronouns based on previous conversation turns and user personality profiles.
* Semantic Weighted Scoring: An algorithm that ranks intent candidates based on keyword density, word boundaries, and historical context.
* Expert Delegation: Dispatches queries to nineteen specialized cognitive experts for domain-specific logic processing.

The system uses a modular expert architecture. Key components include:
* StrategicPlannerExpert: Optimizes task sequencing using Eisenhower matrix principles.
* MentalLoadExpert: Monitors user productivity density to provide burnout prevention insights.
* ResearchExpert: Decomposes high-level goals into actionable task lists.
* SystemIntegrityExpert: Performs real-time audits of task priorities and deadline consistency.
* HabitEvolutionExpert: Analyzes recurring task patterns to suggest automated habit tracking.

### GPU-Accelerated Python FastAPI Sidecar (Nebula Architecture)
The backend uses the Nebula Architecture to run local hardware acceleration for high-performance neural processing.
* Python Sidecar: A FastAPI service bridges the PHP environment to the GPU.
* Model Processing: Runs the all-MiniLM-L6-v2 model for transformer-based embeddings.
* Hardware Optimization: Configured for CUDA 13.0 and Nvidia RTX 3050 or newer hardware.
* Functionality: Provides vector similarity searching and semantic intent mapping to the Laravel application.

## Requirements

The infrastructure requires specific software and hardware configurations to operate efficiently.

### System Prerequisites
* PHP 8.3 or newer
* Composer 2.x
* PostgreSQL 15 or newer
* Redis 7 or newer
* Python 3.10 or newer (required for GPU processing mode)
* Nvidia Driver 530 or newer with CUDA support

## Environment Setup and Installation Procedures

Follow these steps to deploy the backend environment.

### 1. Core Backend Deployment
Clone the repository and install the PHP dependencies:
```bash
git clone https://github.com/skutanjir/To-Do-custom-Backend.git
cd To-Do-custom-Backend
composer install
```

Configure the environment variables. Copy the example configuration file and adjust the database credentials and GPU endpoint:
```bash
cp .env.example .env
```

Edit the `.env` file with the following minimum configuration:
```text
DB_CONNECTION=pgsql
AI_COMPUTE_DEVICE=gpu
GPU_SIDECAR_URL=http://127.0.0.1:8080
```

Initialize the database schema:
```bash
php artisan migrate
```

### 2. Python Sidecar Deployment
Navigate to the AI engine directory and install the neural processing requirements for the Python sidecar:
```bash
cd ai_engine
pip install -r requirements.txt
```

Launch the FastAPI service:
```bash
python main.py
```

### 3. Mobile Client Integration Setup
When connecting mobile clients to the backend infrastructure, ensure the API base URL points to the Laravel application port. The mobile application requires the Bearer token generated from the `/api/login` endpoint for all authenticated requests. Configure the mobile client network interceptors to attach this token to the authorization header. For real-time features, the mobile client must support Server-Sent Events to consume the `/api/ai/stream` endpoint. Voice behavior and text-to-speech settings sync via the `/api/ai/voice-preference` route.

## API Structure

The application programming interface exposes endpoints for authentication, task management, and artificial intelligence features.

### Authentication and Identity Management
| Method | Endpoint | Description | Security |
|--------|----------|-------------|----------|
| POST | `/api/register` | Account creation and device migration | Public |
| POST | `/api/login` | Bearer token generation | Public |
| POST | `/api/logout` | Session termination | Authenticated |

### Task Repository
| Method | Endpoint | Description | Security Level |
|--------|----------|-------------|----------------|
| GET | `/api/todos` | Fetch task list with team merge | Device or User |
| POST | `/api/todos` | Persistent task creation | Validated |
| PUT | `/api/todos/{id}` | Update task metadata | Ownership verified |
| DELETE | `/api/todos/{id}` | Soft or Hard task removal | Ownership verified |

### Artificial Intelligence and Intelligence Hub
| Method | Endpoint | Description | Features |
|--------|----------|-------------|----------|
| POST | `/api/ai/chat` | Main intelligence dispatcher | Multi-intent parsing |
| POST | `/api/ai/stream` | SSE-based response streaming | Real-time chunks |
| GET | `/api/ai/experts/insights` | Expert telemetry and reports | JSON analytics |
| POST | `/api/ai/voice-preference` | TTS and Voice behavior settings | Personality synchronization |

## Performance Optimization and Security

The system architecture includes specific optimizations for high-volume data handling and enterprise security.

### Database Optimization
The PostgreSQL database is optimized for heavy task workloads. Composite indices on `user_id`, `is_completed`, and `team_id` ensure fast retrieval. The Laravel application uses strict eager loading conventions to prevent query overhead and N+1 problems.

### Security Implementation
A centralized verification layer prevents Insecure Direct Object Reference vulnerabilities. The system ensures task access is strictly bound to the authenticated user or their authorized team members. The artificial intelligence engine features a firewall expert that rejects prompts attempting to access data outside the defined task management context.

## License and Terms
This backend software is proprietary. Distribution or modification requires explicit authorization under the MIT License terms.
