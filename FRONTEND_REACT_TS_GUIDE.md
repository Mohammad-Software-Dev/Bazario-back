# Bazario Frontend React + TypeScript Guide

This guide is for building a new frontend for the Bazario Laravel backend in a separate project using:

- React
- TypeScript
- Vite
- Tailwind CSS
- shadcn/ui

It is written to help tutor a junior developer. The sequence matters. Do not start with random pages, random API calls, or isolated component experiments. Start with a stable foundation, then build one complete vertical slice end-to-end.

## 1. High-Level Build Order

Use this order:

1. Scaffold the project
2. Install core dependencies
3. Configure Tailwind and shadcn/ui
4. Set up routing, API client, query client, env handling, and aliases
5. Define the folder structure
6. Define auth and role-based access rules
7. Build one complete vertical slice: login
8. Build the public catalog
9. Build details, cart, checkout, and booking flows
10. Build seller, service provider, and admin dashboards
11. Add chat and real-time WebSocket behavior
12. Add testing, cleanup, and production hardening

## 2. Why This Order

This frontend is not just a collection of screens. It is a role-based application with:

- public catalog pages
- authentication
- protected pages
- multiple roles
- Stripe flows
- booking flows
- admin tools
- real-time messaging

If a junior starts with UI only, they will build components with no real data shape or flow. If they start with API only, they will produce a disconnected services layer with no real user journey.

The correct teaching approach is:

- establish the app shell
- establish the architecture
- build one small real feature fully

That teaches how the frontend actually works as a system.

## 3. Recommended Frontend Stack

Use this stack unless you have a strong reason not to:

- `react`
- `typescript`
- `vite`
- `tailwindcss`
- `@tailwindcss/vite`
- `shadcn/ui`
- `react-router-dom`
- `@tanstack/react-query`
- `axios`
- `react-hook-form`
- `zod`
- `@hookform/resolvers`
- `pusher-js`
- `lucide-react`

Optional later:

- `sonner` for toasts if not already added via shadcn
- `zustand` if lightweight client state grows beyond a simple auth/cart context
- `dayjs` or `date-fns` for booking and schedule formatting

Do not add Redux at the beginning. This backend is API-heavy, and the app will benefit more from a clean server-state approach with React Query than from Redux boilerplate.

## 4. Ground Rules Before Coding

Teach the junior these rules first:

- Server state and UI state are different things.
- API responses should be typed.
- Routes must be designed before pages are implemented.
- Roles must be modeled explicitly.
- Shared components and feature components are not the same.
- Do not duplicate backend business rules in random places in the frontend.
- Loading, error, and empty states are part of the feature, not polish.

## 5. Backend Reality This Frontend Must Match

This frontend should reflect the backend as it exists now:

- Auth is token-based via Laravel Sanctum bearer tokens.
- Roles include `customer`, `seller`, `service_provider`, and `admin`.
- Public flows include products, services, ads, and home content.
- Protected flows include orders, bookings, chats, Stripe Connect, and admin actions.
- Real-time chat uses Pusher/WebSockets, not REST alone.
- Stripe checkout exists server-side and should be consumed from frontend flows.

This matters because the junior should learn to build from real contracts, not assumptions.

## 6. What To Build First

The first feature should be `login`, not the homepage and not the chat.

Reason:

- it includes UI
- it includes validation
- it includes API communication
- it includes token handling
- it includes typed responses
- it includes protected routing
- it includes role-aware redirection

That is the smallest complete frontend lesson in this system.

After login, build public listings:

- products list
- services list
- detail pages

After that, move into:

- cart
- checkout
- bookings
- seller/service provider dashboards
- admin pages
- chat last

Chat should come later because it combines:

- auth
- REST fetches
- message history
- unread counts
- WebSocket subscriptions
- delivery/read events
- reconnect behavior

That is not junior-friendly as the first major feature.

## 7. Project Creation Commands

These commands are based on the current official docs for Vite, Tailwind CSS, and shadcn/ui.

Official references used:

- Vite Getting Started: `npm create vite@latest` with React + TypeScript templates
- Tailwind CSS Vite setup: install `tailwindcss` and `@tailwindcss/vite`
- shadcn/ui Vite installation guide

### 7.1 Choose the project location

Because the new frontend is a separate project, create it outside the Laravel app or as a sibling folder. For example:

```bash
cd /Users/SkyLine/PrivateProjects/freelance/Bazario
npm create vite@latest bazario-frontend -- --template react-ts
cd bazario-frontend
```

If you prefer to stay inside a broader monorepo later, that is fine, but do not complicate the first teaching pass with monorepo tooling.

### 7.2 Check Node.js

Vite currently requires a modern Node.js version. Before installing, verify Node:

```bash
node -v
npm -v
```

If Node is outdated, upgrade before continuing.

## 8. Install Core Dependencies

Install the main app dependencies first:

```bash
npm install react-router-dom @tanstack/react-query axios react-hook-form zod @hookform/resolvers pusher-js lucide-react
```

Install Tailwind for Vite:

```bash
npm install tailwindcss @tailwindcss/vite
```

Install Node type support for alias configuration:

```bash
npm install -D @types/node
```

At this point, do not install every possible library. Keep the junior focused on the app architecture, not package collecting.

## 9. Configure Tailwind CSS

According to the official Tailwind Vite guide, Tailwind should be installed through the Vite plugin.

### 9.1 Update `vite.config.ts`

Add the Tailwind plugin and path alias support:

```ts
import path from "path"
import react from "@vitejs/plugin-react"
import tailwindcss from "@tailwindcss/vite"
import { defineConfig } from "vite"

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
})
```

### 9.2 Update TypeScript path aliases

In `tsconfig.json`:

```json
{
  "files": [],
  "references": [
    { "path": "./tsconfig.app.json" },
    { "path": "./tsconfig.node.json" }
  ],
  "compilerOptions": {
    "baseUrl": ".",
    "paths": {
      "@/*": ["./src/*"]
    }
  }
}
```

In `tsconfig.app.json`, add the same alias under `compilerOptions`:

```json
{
  "compilerOptions": {
    "baseUrl": ".",
    "paths": {
      "@/*": ["./src/*"]
    }
  }
}
```

### 9.3 Replace the CSS entry

In your main CSS file, usually `src/index.css`, use:

```css
@import "tailwindcss";
```

This is the simplest clean setup for Tailwind v4 with Vite.

## 10. Install and Configure shadcn/ui

After Tailwind is working, add shadcn/ui.

From the official Vite guide:

```bash
npx shadcn@latest init
```

Or with the Vite template directly:

```bash
npx shadcn@latest init -t vite
```

Then add the first components you know you will actually use:

```bash
npx shadcn@latest add button input label card form alert dialog sheet select textarea badge separator skeleton sonner
```

Do not install every component in the catalog. Add them when needed.

### 10.1 What to teach here

Explain to the junior:

- shadcn/ui is not a typical component package you hide behind
- components are generated into the project
- they own the code after generation
- shared UI components live in the codebase and can be adapted

That changes how they should think about design systems and maintenance.

## 11. Set Up the Base App Shell

Before building features, set up these pieces:

- router
- query client
- app providers
- base layouts
- error boundaries later
- toast provider

At a minimum, the app should have:

- `App.tsx`
- `main.tsx`
- `src/app/providers`
- `src/app/router`
- `src/layouts`

## 12. Suggested Folder Structure

Use a feature-oriented structure, not a flat file dump.

```text
src/
  app/
    providers/
    router/
  components/
    ui/
    shared/
  features/
    auth/
      api/
      components/
      hooks/
      pages/
      types/
    products/
      api/
      components/
      hooks/
      pages/
      types/
    services/
    cart/
    bookings/
    orders/
    seller/
    service-provider/
    admin/
    chat/
  layouts/
  lib/
    api/
    auth/
    utils/
    constants/
  hooks/
  types/
  styles/
```

### 12.1 What this teaches

The junior should learn:

- shared code goes in `lib`, `components/shared`, or `components/ui`
- feature logic stays near the feature
- API modules should be close to the feature they belong to
- page components should not contain every detail of the feature

## 13. Configure Environment Variables

Do this early, before hardcoding anything.

Create a `.env` file in the frontend project:

```bash
touch .env
```

Use Vite-style environment variables:

```env
VITE_API_BASE_URL=http://127.0.0.1:8000/api
VITE_PUSHER_KEY=your_pusher_key
VITE_PUSHER_CLUSTER=mt1
```

If the Laravel backend runs on another port, adjust accordingly.

Teach the junior:

- browser-side env vars in Vite must start with `VITE_`
- secrets do not belong in frontend env files if they are truly private
- publishable Stripe keys are fine in frontend env
- server secrets stay in Laravel

## 14. Create the API Client Before Feature Work

Do not start with page-local `fetch()` calls.

Create one shared API client using `axios`:

- base URL from env
- auth header injection
- consistent error normalization
- optional interceptors later

What to teach:

- pages should not know how to build base URLs
- token handling should be centralized
- a typed API layer reduces duplicated request code

Suggested internal modules:

- `src/lib/api/client.ts`
- `src/lib/api/types.ts`
- `src/lib/auth/token.ts`

## 15. React Query Setup

Set up React Query immediately after the API client.

Why:

- product lists
- service lists
- orders
- bookings
- unread counts
- admin settings

These are server state. React Query handles caching, retries, loading, invalidation, and synchronization far better than hand-rolled state.

What to teach:

- query for reading server data
- mutation for changing server data
- invalidate related queries after successful writes
- loading and error are part of the feature contract

## 16. Router and Role-Based Access

Define the route tree before building pages.

Example route groups:

- public routes
- authenticated routes
- role-restricted routes

Public:

- `/`
- `/login`
- `/register`
- `/products`
- `/services`
- `/products/:id`
- `/services/:id`

Authenticated:

- `/account`
- `/orders`
- `/bookings`
- `/chat`

Seller:

- `/seller/products`
- `/seller/orders`
- `/seller/ads`
- `/seller/connect`

Service provider:

- `/provider/services`
- `/provider/bookings`
- `/provider/availability`
- `/provider/connect`

Admin:

- `/admin/upgrade-requests`
- `/admin/payouts`
- `/admin/settings`

What to teach:

- protect route groups, not random buttons only
- route protection is not security by itself; backend still enforces roles
- frontend guards are for UX and navigation flow

## 17. Auth Design

Teach auth early and keep it explicit.

The backend uses token-based auth through Sanctum bearer tokens, so the frontend should:

- submit login credentials
- receive token/user payload from the backend contract
- store the token in a controlled way
- attach the token to authenticated requests
- fetch the current user if the flow requires it
- derive roles from the user payload

Topics to explain to the junior:

- difference between authentication and authorization
- why role checks must not be spread through raw string checks everywhere
- why logout must clear token, cached user data, and protected views

## 18. First Teaching Feature: Login Vertical Slice

Build this first:

1. Login page UI
2. Form validation with `react-hook-form` + `zod`
3. Login mutation with React Query
4. Token storage
5. Current user state
6. Protected route
7. Role-aware redirect

This first slice teaches:

- component composition
- form state
- schema validation
- mutation handling
- error display
- auth persistence
- routing after success

This is much more valuable than starting with a polished homepage.

## 19. Second Feature: Public Catalog

Once auth works, move to read-heavy public pages:

- product listing
- service listing
- cards
- filters if supported
- pagination if supported
- loading skeletons
- empty states
- detail pages

What to teach:

- query hooks
- typed response mapping
- reusable card and list components
- route params
- search params

This stage teaches how to render real server data cleanly.

## 20. Third Feature Group: Cart, Checkout, and Booking

Only after the junior understands queries, mutations, and routing should they build:

- cart state
- order creation
- service booking flow
- Stripe checkout handoff

Teach the boundary clearly:

- backend owns prices, totals, and payment intent/session creation
- frontend owns user interaction, validation, and flow state
- frontend should not invent totals that disagree with backend truth

## 21. Role Dashboards

After public and auth flows are stable, build role pages:

- seller product management
- seller sales/orders
- service provider services
- service provider availability
- provider bookings
- admin upgrade approvals
- admin payouts
- admin settings

This is where the junior should learn layout reuse:

- dashboard shell
- sidebar/nav
- page header pattern
- table/list pattern
- reusable form sections

## 22. Chat Comes Later

Do not start with chat.

Build chat after:

- auth works
- current user works
- conversation list fetch works
- message history fetch works

Then add Pusher/WebSocket support for:

- new message events
- unread count updates
- delivery/read updates

Teach the junior that chat uses both:

- REST for initial data and history
- WebSockets for real-time updates

This is where communication architecture becomes real, not theoretical.

## 23. UI vs API: What To Start With

Do not teach this as:

- first all API
- then all UI

And do not teach it as:

- first make every page look nice
- then connect data later

Teach this instead:

- foundation first
- then one vertical slice with both UI and API

That means:

1. install and configure the stack
2. define architecture
3. build login end-to-end
4. build catalog end-to-end

This is the cleanest learning path.

## 24. What To Install Immediately vs Later

Install immediately:

- routing
- query client
- axios
- form validation
- Tailwind
- shadcn/ui

Delay until needed:

- charting libraries
- advanced table libraries
- drag and drop
- calendar libraries
- date utilities beyond a small formatter
- WebSocket helpers beyond `pusher-js`

This keeps the junior focused on fundamentals.

## 25. Suggested Early Deliverables

Use these as milestones:

### Milestone 1

- app boots
- Tailwind works
- shadcn works
- alias works
- router works
- query client works

### Milestone 2

- login page works
- token persists
- protected route works
- logout works

### Milestone 3

- products list from API works
- services list from API works
- loading/error/empty states exist

### Milestone 4

- product and service detail pages work
- add to cart flow starts

### Milestone 5

- order/checkout flow works
- booking flow works

### Milestone 6

- seller/provider/admin sections start

### Milestone 7

- chat works with real-time updates

## 26. Suggested Teaching Sessions

If you are tutoring a junior, split the work like this:

### Session 1

- Explain the backend domains
- Explain the route groups
- Scaffold the project
- Install Tailwind and shadcn

### Session 2

- Build the folder structure
- Set up router, providers, env, and API client
- Explain server state vs UI state

### Session 3

- Build login
- Add validation
- Add token storage
- Add protected routes

### Session 4

- Build products/services lists
- Add reusable cards
- Add loading and empty states

### Session 5

- Build details and cart basics

### Session 6

- Build checkout and booking flows

### Session 7

- Build dashboards

### Session 8

- Build chat and real-time behavior

## 27. Commands Summary

Scaffold:

```bash
npm create vite@latest bazario-frontend -- --template react-ts
cd bazario-frontend
```

Install app packages:

```bash
npm install react-router-dom @tanstack/react-query axios react-hook-form zod @hookform/resolvers pusher-js lucide-react
```

Install Tailwind:

```bash
npm install tailwindcss @tailwindcss/vite
```

Install Node types:

```bash
npm install -D @types/node
```

Initialize shadcn:

```bash
npx shadcn@latest init -t vite
```

Add common UI components:

```bash
npx shadcn@latest add button input label card form alert dialog sheet select textarea badge separator skeleton sonner
```

Run dev server:

```bash
npm run dev
```

## 28. Final Recommendation

Start by installing and configuring the stack, but do not stop there and do not immediately branch into random screens.

The correct first implementation path is:

1. Scaffold
2. Install core packages
3. Configure Tailwind and shadcn
4. Set up router, env, API client, and React Query
5. Build login as the first complete vertical slice
6. Move to catalog pages

If you tutor the junior this way, they will learn how to build a real frontend system instead of just assembling pages.

## 29. Sources

This guide’s setup commands were checked against:

- Vite Getting Started: https://vite.dev/guide/
- Tailwind CSS with Vite: https://tailwindcss.com/docs/installation/using-vite
- shadcn/ui Vite installation: https://ui.shadcn.com/docs/installation/vite
