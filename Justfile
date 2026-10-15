# List available recipes (default)
default:
    @just --list

# Dev server
start *args:
    #!/usr/bin/env bash
    port="8080"
    mode="foreground"
    if [ -f .server.pid ] && kill -0 $(cat .server.pid) 2>/dev/null; then
        echo "Server already running (PID: $(cat .server.pid))"
        exit 0
    fi
    for arg in {{args}}; do
        if [ "$arg" = "background" ]; then
            mode="background"
        elif [[ "$arg" =~ ^[0-9]+$ ]]; then
            port="$arg"
        fi
    done
    if [ "$mode" = "foreground" ]; then
        php -d realpath_cache_size=0 -d realpath_cache_ttl=0 -S localhost:$port index.php
    else
        php -d realpath_cache_size=0 -d realpath_cache_ttl=0 -S localhost:$port index.php > /dev/null 2>&1 &
        echo $! > .server.pid
        echo "Server started on port $port (PID: $(cat .server.pid))"
    fi

# Stop dev server
stop:
    @if [ -f .server.pid ]; then \
        kill $(cat .server.pid) 2>/dev/null && echo "Server stopped" || echo "Server not running"; \
        rm -f .server.pid; \
    else \
        echo "Server not running"; \
    fi

# Run test suite
test:
    php tests.php
