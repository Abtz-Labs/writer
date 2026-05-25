FROM node:20-slim

WORKDIR /app

COPY package*.json ./

RUN npm ci --only=production

COPY . .

RUN mkdir -p data

EXPOSE 3000

CMD ["node", "app.js"]