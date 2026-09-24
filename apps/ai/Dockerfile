FROM python:3.12-slim

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements
COPY requirements.txt /app/apps/ai/requirements.txt
RUN pip install --no-cache-dir -r /app/apps/ai/requirements.txt

# Copy source code
COPY . /app/apps/ai

ENV PYTHONPATH=/app
ENV PORT=8001

EXPOSE 8001

CMD ["uvicorn", "apps.ai.src.main:app", "--host", "0.0.0.0", "--port", "8001"]
