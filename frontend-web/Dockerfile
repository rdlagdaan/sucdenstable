# ---------- Build Stage ----------
FROM node:20-alpine AS build
WORKDIR /app

# Install deps
COPY package*.json ./
RUN npm install

# Build
COPY . .
RUN npm run build

# ---------- Nginx Stage ----------
FROM nginx:alpine

# Use our custom Nginx config (adds /api and /sanctum proxy to app:8686)
COPY nginx.conf /etc/nginx/nginx.conf

# Serve the built SPA
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
