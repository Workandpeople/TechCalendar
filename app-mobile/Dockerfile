FROM node:20.19.4-bullseye

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

EXPOSE 8081

CMD ["npm", "run", "start:docker"]
