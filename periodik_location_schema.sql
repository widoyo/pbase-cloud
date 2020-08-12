--
-- PostgreSQL database dump
--

-- Dumped from database version 10.11
-- Dumped by pg_dump version 10.11

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: location; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.location (
    id integer NOT NULL,
    nama character varying(25) NOT NULL,
    ll character varying(50),
    tenant_id integer,
    created_at timestamp with time zone DEFAULT now(),
    modified_at timestamp with time zone,
    tipe character(1),
    elevasi double precision,
    wilayah character varying(15)
);


ALTER TABLE public.location OWNER TO postgres;

--
-- Name: TABLE location; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.location IS 'Lokasi, dimana bisa ditempati logger';


--
-- Name: COLUMN location.tenant_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.location.tenant_id IS 'FK ke table tenant';


--
-- Name: COLUMN location.tipe; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.location.tipe IS '"1" arr, "2" awlr, "4" klimato';


--
-- Name: location_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.location_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.location_id_seq OWNER TO postgres;

--
-- Name: location_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.location_id_seq OWNED BY public.location.id;


--
-- Name: periodik; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.periodik (
    id integer NOT NULL,
    sampling timestamp without time zone,
    up_s timestamp without time zone,
    ts_a timestamp without time zone,
    received timestamp without time zone DEFAULT now() NOT NULL,
    mdpl double precision,
    apre double precision,
    sq integer,
    temp double precision,
    humi double precision,
    batt double precision,
    rain double precision,
    wlev double precision,
    logger_sn character varying(8),
    location_id integer,
    tenant_id integer
);


ALTER TABLE public.periodik OWNER TO postgres;

--
-- Name: periodik_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.periodik_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.periodik_id_seq OWNER TO postgres;

--
-- Name: periodik_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.periodik_id_seq OWNED BY public.periodik.id;


--
-- Name: location id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.location ALTER COLUMN id SET DEFAULT nextval('public.location_id_seq'::regclass);


--
-- Name: periodik id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.periodik ALTER COLUMN id SET DEFAULT nextval('public.periodik_id_seq'::regclass);


--
-- Name: location location_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.location
    ADD CONSTRAINT location_pkey UNIQUE (id);


--
-- Name: periodik periodik_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.periodik
    ADD CONSTRAINT periodik_pkey PRIMARY KEY (id);


--
-- Name: periodik periodik_location_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.periodik
    ADD CONSTRAINT periodik_location_id_fkey FOREIGN KEY (location_id) REFERENCES public.location(id);


--
-- Name: periodik periodik_logger_sn_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.periodik
    ADD CONSTRAINT periodik_logger_sn_fkey FOREIGN KEY (logger_sn) REFERENCES public.logger(sn);


--
-- Name: periodik periodik_tenant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.periodik
    ADD CONSTRAINT periodik_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES public.tenant(id);


--
-- PostgreSQL database dump complete
--

